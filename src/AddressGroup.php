<?php

namespace Chargit\AddressComponent;

use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

class AddressGroup
{
    /**
     * Create an address form fieldset with postcode lookup functionality.
     *
     * @param  string  $prefix  The form field prefix for nested data (e.g., 'address' for 'address.street')
     * @param  bool  $required  Whether the address fields are required
     */
    public const COUNTRIES = [
        'NLD' => 'Nederland',
        'BEL' => 'België',
        'DEU' => 'Duitsland',
        'FRA' => 'Frankrijk',
        'LUX' => 'Luxemburg',
    ];

    /**
     * Create an address form fieldset with postcode lookup functionality.
     *
     * @param  string  $prefix  The form field prefix for nested data (e.g., 'address' for 'address.street')
     * @param  bool|Closure  $required  Whether the address fields are required. Accepts a Closure so callers can make the address optional based on form state (e.g. a not-yet-activated user).
     * @param  bool  $showCoordinates  Whether to show latitude/longitude as visible fields
     * @param  bool  $coordinatesRequired  Whether GPS coordinates are required (only effective with $showCoordinates)
     */
    public static function make(string $prefix = 'address', bool|Closure $required = true, bool $showCoordinates = false, bool $coordinatesRequired = false): Grid
    {
        return Grid::make()
            ->schema([
                TextInput::make($prefix.'.postalCode')
                    ->label('Postcode')
                    ->required($required)
                    ->maxLength(10)
                    ->autocomplete('postal-code')
                    // Een Nederlandse postcode moet altijd 4 cijfers + 2 letters
                    // bevatten (bijv. 1234 AB). Zonder de letters levert de
                    // postcode-lookup een verkeerde straat op, dus we blokkeren
                    // het opslaan van een onvolledige postcode.
                    ->rules([
                        fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($prefix, $get): void {
                            $country = $get($prefix.'.country') ?: 'NLD';

                            if ($country === 'NLD' && filled($value) && ! self::isCompleteDutchPostalCode((string) $value)) {
                                $fail('Vul een geldige postcode in, bijvoorbeeld 1234 AB.');
                            }
                        },
                    ])
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (Get $get, Set $set, ?string $state) use ($prefix) {
                        if (blank($state)) {
                            self::clearLookupResults($prefix, $set);

                            return;
                        }

                        self::autoLookup($prefix, $get, $set);
                    }),
                TextInput::make($prefix.'.houseNumber')
                    ->label('Huisnummer')
                    ->required($required)
                    ->maxLength(10)
                    // Alleen cijfers in het huisnummer; letters horen in
                    // 'Toevoeging'. De mask blokkeert letters al bij het typen,
                    // de regex-regel vangt plak-/no-JS-invoer server-side af.
                    ->mask('9999999999')
                    ->rule('regex:/^[0-9]*$/')
                    ->extraInputAttributes(['autocomplete' => 'address-line2', 'inputmode' => 'numeric'])
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (Get $get, Set $set) => self::autoLookup($prefix, $get, $set)),
                TextInput::make($prefix.'.addition')
                    ->label('Toevoeging')
                    ->maxLength(10),
                TextInput::make($prefix.'.street')
                    ->label('Straat')
                    ->required($required)
                    ->maxLength(255)
                    ->autocomplete('street-address'),
                TextInput::make($prefix.'.city')
                    ->label('Woonplaats')
                    ->required($required)
                    ->maxLength(255)
                    ->autocomplete('address-level2'),
                Select::make($prefix.'.country')
                    ->label('Land')
                    ->options(self::COUNTRIES)
                    ->default('NLD')
                    ->required($required)
                    ->native(false)
                    ->live()
                    ->extraInputAttributes(['autocomplete' => 'country']),
                ...self::getCoordinateFields($prefix, $showCoordinates, $coordinatesRequired),
            ])
            ->columns(3)
            ->columnSpanFull();
    }

    /**
     * Get coordinate fields based on visibility setting.
     *
     * @return array<int, Hidden|TextInput>
     */
    private static function getCoordinateFields(string $prefix, bool $showCoordinates, bool $coordinatesRequired = false): array
    {
        if ($showCoordinates) {
            return [
                Hidden::make($prefix.'.latitude')->required($coordinatesRequired),
                Hidden::make($prefix.'.longitude')->required($coordinatesRequired),
                TextInput::make($prefix.'.coordinates')
                    ->label('GPS locatie')
                    ->placeholder('52.123456, 4.123456')
                    ->required($coordinatesRequired)
                    ->columnSpan(2)
                    ->dehydrated(false)
                    ->afterStateHydrated(function (TextInput $component, Get $get) use ($prefix) {
                        $lat = $get($prefix.'.latitude');
                        $lng = $get($prefix.'.longitude');
                        if ($lat && $lng) {
                            $component->state($lat.', '.$lng);
                        }
                    })
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (Set $set, ?string $state) use ($prefix) {
                        if (blank($state)) {
                            $set($prefix.'.latitude', null);
                            $set($prefix.'.longitude', null);

                            return;
                        }
                        $parts = array_map('trim', explode(',', $state));
                        $set($prefix.'.latitude', $parts[0]);
                        $set($prefix.'.longitude', $parts[1] ?? null);
                    })
                    ->suffixAction(
                        Action::make('fetchCoordinates')
                            ->icon('heroicon-m-map-pin')
                            ->tooltip('Haal GPS locatie op uit het adres')
                            ->action(function (Get $get, Set $set) use ($prefix) {
                                $result = app(PostcodeLookupService::class)->geocodeCoordinates([
                                    'street' => $get($prefix.'.street'),
                                    'houseNumber' => $get($prefix.'.houseNumber'),
                                    'addition' => $get($prefix.'.addition'),
                                    'postalCode' => $get($prefix.'.postalCode'),
                                    'city' => $get($prefix.'.city'),
                                    'country' => $get($prefix.'.country') ?? 'NLD',
                                ]);

                                if ($result === null) {
                                    return;
                                }

                                $set($prefix.'.latitude', $result['latitude']);
                                $set($prefix.'.longitude', $result['longitude']);
                                $set($prefix.'.coordinates', $result['latitude'].', '.$result['longitude']);
                            })
                    ),
            ];
        }

        return [
            Hidden::make($prefix.'.latitude'),
            Hidden::make($prefix.'.longitude'),
        ];
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
     * Clear the fields that are populated by the postcode/house-number lookup
     * so a cleared postcode doesn't leave a stale street/city behind.
     */
    private static function clearLookupResults(string $prefix, Set $set): void
    {
        $set($prefix.'.street', null);
        $set($prefix.'.city', null);
        $set($prefix.'.latitude', null);
        $set($prefix.'.longitude', null);
        $set($prefix.'.coordinates', null);
    }

    /**
     * Auto-lookup address when both postalCode and houseNumber are filled.
     * Works for all supported countries.
     *
     * Only street and city are filled automatically — GPS coordinates must be
     * fetched explicitly via the "Haal GPS locatie op" action. For non-existing
     * postcode/huisnummer combinations the lookup service falls back to a
     * fuzzy match (Google Maps geocoder) that may return coordinates for a
     * nearby/approximate location, so auto-filling GPS gives a misleading
     * impression of accuracy.
     */
    private static function autoLookup(string $prefix, Get $get, Set $set): void
    {
        $country = $get($prefix.'.country') ?? 'NLD';

        $postalCode = $get($prefix.'.postalCode');
        $houseNumber = $get($prefix.'.houseNumber');

        // Only lookup if both fields are filled
        if (! $postalCode || ! $houseNumber) {
            return;
        }

        // Een onvolledige Nederlandse postcode (zonder de 2 letters) zou een
        // fuzzy match en daarmee een verkeerde straat opleveren; sla de lookup
        // dan over tot de postcode compleet is.
        if ($country === 'NLD' && ! self::isCompleteDutchPostalCode((string) $postalCode)) {
            return;
        }

        $result = app(PostcodeLookupService::class)->lookup($postalCode, $houseNumber, $country);

        if ($result) {
            $set($prefix.'.street', $result['street']);
            $set($prefix.'.city', $result['city']);
        }
    }
}
