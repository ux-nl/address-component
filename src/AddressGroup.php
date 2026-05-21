<?php

namespace Chargit\AddressComponent;

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
     * @param  bool  $required  Whether the address fields are required
     * @param  bool  $showCoordinates  Whether to show latitude/longitude as visible fields
     * @param  bool  $coordinatesRequired  Whether GPS coordinates are required (only effective with $showCoordinates)
     */
    public static function make(string $prefix = 'address', bool $required = true, bool $showCoordinates = false, bool $coordinatesRequired = false): Grid
    {
        return Grid::make()
            ->schema([
                TextInput::make($prefix.'.postalCode')
                    ->label('Postcode')
                    ->required($required)
                    ->maxLength(10)
                    ->autocomplete('postal-code')
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
                    ->extraInputAttributes(['autocomplete' => 'address-line2'])
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (Get $get, Set $set) => self::autoLookup($prefix, $get, $set)),
                TextInput::make($prefix.'.addition')
                    ->label('Toevoeging')
                    ->maxLength(10),
                TextInput::make($prefix.'.street')
                    ->label('Straat')
                    ->required($required)
                    ->maxLength(255)
                    ->columnSpan(2)
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

        $result = app(PostcodeLookupService::class)->lookup($postalCode, $houseNumber, $country);

        if ($result) {
            $set($prefix.'.street', $result['street']);
            $set($prefix.'.city', $result['city']);
            if (isset($result['latitude'])) {
                $set($prefix.'.latitude', $result['latitude']);
            }
            if (isset($result['longitude'])) {
                $set($prefix.'.longitude', $result['longitude']);
            }
            if (isset($result['latitude'], $result['longitude'])) {
                $set($prefix.'.coordinates', $result['latitude'].', '.$result['longitude']);
            }
        }
    }
}
