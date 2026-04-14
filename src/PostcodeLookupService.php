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

            if (empty($result) || ! isset($result['street'], $result['city'])) {
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
        $street = '';
        $city = '';

        foreach ($components as $component) {
            $type = $component['types'][0] ?? '';

            if ($type === 'route') {
                $street = $component['long_name'];
            } elseif ($type === 'locality') {
                $city = $component['long_name'];
            }
        }

        return ['street' => $street, 'city' => $city];
    }

    /**
     * Send notification when address is not found.
     */
    private function sendNotFoundNotification(): void
    {
        Notification::make()
            ->warning()
            ->title('Adres niet gevonden')
            ->body('De postcode en huisnummer combinatie is niet gevonden. U kunt het adres handmatig invullen.')
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
