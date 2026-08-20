<?php

namespace Chargit\AddressComponent;

use Baspa\ZipCodeLocationLookup\ZipCodeLocationLookup;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PostcodeLookupService
{
    /**
     * ISO 3166-1 alpha-3 to alpha-2 country code mapping for supported countries.
     */
    private const COUNTRY_ALPHA2_MAP = [
        'NLD' => 'NL',
        'BEL' => 'BE',
        'DEU' => 'DE',
        'FRA' => 'FR',
        'LUX' => 'LU',
    ];

    public function __construct(
        private ?ZipCodeLocationLookup $lookup = null
    ) {
        if ($this->lookup === null) {
            $this->lookup = new ZipCodeLocationLookup(useGoogleMaps: true);
        }
    }

    /**
     * Lookup address information by postal code and house number.
     * For Dutch addresses, uses the postcode.tech + Google Maps combo.
     * For foreign addresses, uses Google Maps Geocoding API directly.
     *
     * @param  string  $postalCode  Postal code
     * @param  string  $houseNumber  House number
     * @param  string  $country  ISO 3166-1 alpha-3 country code (default: NLD)
     * @return array{street: string, city: string, postalCode: string, houseNumber: string, latitude: string|null, longitude: string|null}|null
     */
    public function lookup(string $postalCode, string $houseNumber, string $country = 'NLD'): ?array
    {
        if ($country !== 'NLD') {
            return $this->geocode($postalCode, $houseNumber, $country);
        }

        $cleanPostcode = str_replace(' ', '', strtoupper($postalCode));
        $houseNumberInt = (int) $houseNumber;

        try {
            $result = $this->lookup->lookup(zipCode: $cleanPostcode, number: $houseNumberInt);

            // Niet `isset()`: het Google-fallbackpad in ZipCodeLocationLookup
            // levert bij een postcodecentroïde zonder `route` een lege straat
            // op, en `isset('')` is true. Die kwam er ongezien doorheen en
            // maakte het straatveld leeg in plaats van het te vullen.
            if (blank($result['street'] ?? null) || blank($result['city'] ?? null)) {
                $this->sendNotFoundNotification();

                Log::warning('Postcode lookup returned no results', [
                    'postalCode' => $postalCode,
                    'houseNumber' => $houseNumber,
                    'result' => $result,
                ]);

                return null;
            }

            return [
                'street' => $result['street'],
                'city' => $result['city'],
                'postalCode' => $cleanPostcode,
                'houseNumber' => $houseNumber,
                'latitude' => isset($result['lat']) ? (string) $result['lat'] : null,
                'longitude' => isset($result['lng']) ? (string) $result['lng'] : null,
            ];
        } catch (\Exception $e) {
            $this->sendErrorNotification();

            Log::error('Postcode lookup failed with exception', [
                'postalCode' => $postalCode,
                'houseNumber' => $houseNumber,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return null;
        }
    }

    /**
     * Resolve the coordinates of an address for the "fetch coordinates from
     * address" action.
     *
     * A Dutch postcode and house number go through the same lookup() the
     * automatic lookup uses, so the button can never contradict what typing
     * the address already filled in. Only when that lookup cannot answer — a
     * half-typed postcode, a missing house number, a foreign address — does
     * this fall back to geocoding whatever fields are filled in as free text.
     *
     * @param  array{street?: ?string, houseNumber?: ?string, addition?: ?string, postalCode?: ?string, city?: ?string, country?: ?string}  $address
     * @return array{latitude: string, longitude: string}|null
     */
    public function geocodeCoordinates(array $address): ?array
    {
        if ($this->isResolvableByPostcode($address)) {
            return $this->coordinatesFromLookup($address);
        }

        $apiKey = config('address-component.google_api_key');

        if (empty($apiKey)) {
            Log::error('Google Maps API key is not configured');
            $this->sendErrorNotification();

            return null;
        }

        $country = $address['country'] ?? 'NLD';
        $alpha2 = self::COUNTRY_ALPHA2_MAP[$country] ?? null;

        if (! $alpha2) {
            Log::warning('Unsupported country code for geocoding', ['country' => $country]);
            $this->sendNotFoundNotification();

            return null;
        }

        $streetLine = trim(($address['street'] ?? '').' '.($address['houseNumber'] ?? '').($address['addition'] ?? ''));
        $localityLine = trim(($address['postalCode'] ?? '').' '.($address['city'] ?? ''));

        $query = trim($streetLine.', '.$localityLine, ', ');

        if ($query === '') {
            $this->sendNotFoundNotification();

            return null;
        }

        try {
            $response = Http::get('https://maps.googleapis.com/maps/api/geocode/json', [
                'key' => $apiKey,
                'address' => $query,
                'components' => 'country:'.$alpha2,
            ]);

            if (! $response->successful()) {
                $this->sendErrorNotification();

                return null;
            }

            $data = $response->json();

            if (($data['status'] ?? '') !== 'OK' || empty($data['results'][0]['geometry']['location'])) {
                $this->sendNotFoundNotification();

                Log::warning('Google Maps coordinate lookup returned no results', [
                    'query' => $query,
                    'country' => $country,
                    'status' => $data['status'] ?? 'unknown',
                ]);

                return null;
            }

            $location = $data['results'][0]['geometry']['location'];

            return [
                'latitude' => (string) $location['lat'],
                'longitude' => (string) $location['lng'],
            ];
        } catch (\Exception $e) {
            $this->sendErrorNotification();

            Log::error('Google Maps coordinate lookup failed', [
                'query' => $query,
                'country' => $country,
                'exception' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Whether the given value is a complete Dutch postal code: four digits
     * (not starting with 0) followed by two letters, optionally separated by a
     * space — e.g. "1234AB" or "1234 AB".
     */
    public static function isCompleteDutchPostalCode(?string $value): bool
    {
        return (bool) preg_match('/^[1-9][0-9]{3}\s?[A-Za-z]{2}$/', trim((string) $value));
    }

    /**
     * Whether lookup() can answer for this address, which for a Dutch one is
     * the authoritative source: the register returns the coordinates of that
     * specific building, while geocoding the same address as free text returns
     * Google's closest match — up to a hundred metres away, and sometimes the
     * neighbouring house under a different postcode.
     *
     * Outside the Netherlands there is no register to consult and both routes
     * end up at Google anyway, so the free-text query wins there: it carries
     * the street and city the user typed instead of a bare postcode.
     *
     * @param  array{street?: ?string, houseNumber?: ?string, addition?: ?string, postalCode?: ?string, city?: ?string, country?: ?string}  $address
     */
    private function isResolvableByPostcode(array $address): bool
    {
        if (blank($address['postalCode'] ?? null) || blank($address['houseNumber'] ?? null)) {
            return false;
        }

        if (($address['country'] ?? 'NLD') !== 'NLD') {
            return false;
        }

        // An incomplete postcode would make the register fuzzy-match and land
        // on a different street, exactly as it would during the automatic
        // lookup, which skips it for the same reason.
        return self::isCompleteDutchPostalCode((string) $address['postalCode']);
    }

    /**
     * Resolve coordinates through the postcode lookup. Returns null when the
     * address is unknown — lookup() has reported why by then — and when it is
     * known but carries no coordinates.
     *
     * @param  array{street?: ?string, houseNumber?: ?string, addition?: ?string, postalCode?: ?string, city?: ?string, country?: ?string}  $address
     * @return array{latitude: string, longitude: string}|null
     */
    private function coordinatesFromLookup(array $address): ?array
    {
        $result = $this->lookup(
            (string) $address['postalCode'],
            (string) $address['houseNumber'],
            $address['country'] ?? 'NLD',
        );

        if ($result === null) {
            return null;
        }

        if (blank($result['latitude']) || blank($result['longitude'])) {
            $this->sendNotFoundNotification();

            Log::warning('Postcode lookup returned an address without coordinates', [
                'postalCode' => $address['postalCode'],
                'houseNumber' => $address['houseNumber'],
                'country' => $address['country'] ?? 'NLD',
            ]);

            return null;
        }

        return [
            'latitude' => $result['latitude'],
            'longitude' => $result['longitude'],
        ];
    }

    /**
     * Geocode a foreign address using Google Maps Geocoding API.
     *
     * @return array{street: string, city: string, postalCode: string, houseNumber: string, latitude: string|null, longitude: string|null}|null
     */
    private function geocode(string $postalCode, string $houseNumber, string $country): ?array
    {
        $apiKey = config('address-component.google_api_key');

        if (empty($apiKey)) {
            Log::error('Google Maps API key is not configured');
            $this->sendErrorNotification();

            return null;
        }

        $alpha2 = self::COUNTRY_ALPHA2_MAP[$country] ?? null;

        if (! $alpha2) {
            Log::warning('Unsupported country code for geocoding', ['country' => $country]);
            $this->sendNotFoundNotification();

            return null;
        }

        try {
            $query = $postalCode.' '.$houseNumber;

            $response = Http::get('https://maps.googleapis.com/maps/api/geocode/json', [
                'key' => $apiKey,
                'address' => $query,
                'components' => 'country:'.$alpha2,
            ]);

            if (! $response->successful()) {
                $this->sendErrorNotification();

                return null;
            }

            $data = $response->json();

            if (($data['status'] ?? '') !== 'OK' || empty($data['results'][0])) {
                $this->sendNotFoundNotification();

                Log::warning('Google Maps geocoding returned no results', [
                    'postalCode' => $postalCode,
                    'houseNumber' => $houseNumber,
                    'country' => $country,
                    'status' => $data['status'] ?? 'unknown',
                ]);

                return null;
            }

            $result = $data['results'][0];
            $components = $this->parseAddressComponents($result['address_components'] ?? []);
            $location = $result['geometry']['location'] ?? [];

            // Een buitenlandse postcode zonder herkend huisnummer levert net als
            // in Nederland een centroïde zonder straat. Dit pad komt niet langs
            // de guard in lookup(), dus hier zelf afvangen: beter het bestaande
            // adres laten staan dan het leegmaken.
            if ($components['street'] === '' || $components['city'] === '') {
                $this->sendNotFoundNotification();

                Log::warning('Google Maps geocoding returned no usable address', [
                    'postalCode' => $postalCode,
                    'houseNumber' => $houseNumber,
                    'country' => $country,
                ]);

                return null;
            }

            return [
                'street' => $components['street'],
                'city' => $components['city'],
                'postalCode' => $postalCode,
                'houseNumber' => $houseNumber,
                'latitude' => isset($location['lat']) ? (string) $location['lat'] : null,
                'longitude' => isset($location['lng']) ? (string) $location['lng'] : null,
            ];
        } catch (\Exception $e) {
            $this->sendErrorNotification();

            Log::error('Google Maps geocoding failed', [
                'postalCode' => $postalCode,
                'houseNumber' => $houseNumber,
                'country' => $country,
                'exception' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Parse Google Maps address components into a simple array.
     *
     * @param  array<int, array<string, mixed>>  $components
     * @return array{street: string, city: string}
     */
    private function parseAddressComponents(array $components): array
    {
        $values = [];

        // Elk component draagt een lijst types en de volgorde ligt niet vast,
        // dus scan ze allemaal in plaats van alleen `types[0]`: een `locality`
        // komt binnen als ["political", "locality"] en viel zo weg.
        foreach ($components as $component) {
            foreach ((array) ($component['types'] ?? []) as $type) {
                $values[$type] ??= (string) ($component['long_name'] ?? '');
            }
        }

        return [
            'street' => $values['route'] ?? '',
            // Grotere steden leveren `locality`; kleinere kernen soms alleen een
            // `postal_town` of de gemeente.
            'city' => $values['locality'] ?? ($values['postal_town'] ?? ($values['administrative_area_level_2'] ?? '')),
        ];
    }

    /**
     * Send notification when address is not found.
     */
    private function sendNotFoundNotification(): void
    {
        Notification::make()
            ->warning()
            ->title('Adres niet gevonden')
            ->body('De postcode en huisnummer combinatie is niet gevonden. Je kunt het adres handmatig invullen.')
            ->send();
    }

    /**
     * Send notification when an error occurs.
     */
    private function sendErrorNotification(): void
    {
        Notification::make()
            ->danger()
            ->persistent()
            ->title('Er is iets misgegaan')
            ->body('Het adres kon niet worden opgehaald. Probeer het later opnieuw.')
            ->send();
    }
}
