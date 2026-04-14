<?php

namespace Chargit\AddressComponent;

use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component as FilamentComponent;
use Filament\Schemas\Components\Fieldset;
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
     */
    public static function make(string $prefix = 'address', bool $required = true, bool $showCoordinates = false): Fieldset
    {
        return Fieldset::make('Adres')
            ->schema([
                TextInput::make($prefix.'.postalCode')
                    ->label('Postcode')
                    ->required($required)
                    ->maxLength(10)
                    ->autocomplete('postal-code')
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (Get $get, Set $set) => self::autoLookup($prefix, $get, $set)),
                TextInput::make($prefix.'.houseNumber')
                    ->label('Huisnummer')
                    ->required($required)
                    ->maxLength(10)
                    ->extraInputAttributes(['autocomplete' => 'address-line2'])
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (Get $get, Set $set) => self::autoLookup($prefix, $get, $set))
                    ->suffixAction(
                        Action::make('lookupAddress')
                            ->icon('chargit-magnifying-glass')
                            ->tooltip('Zoek adres op basis van postcode en huisnummer')
                            ->action(function (Get $schemaGet, FilamentComponent $component) use ($prefix) {
                                $postalCode = $schemaGet($prefix.'.postalCode');
                                $houseNumber = $schemaGet($prefix.'.houseNumber');
                                $country = $schemaGet($prefix.'.country') ?? 'NLD';

                                if (! $postalCode || ! $houseNumber) {
                                    return;
                                }

                                $result = app(PostcodeLookupService::class)->lookup($postalCode, $houseNumber, $country);

                                if ($result) {
                                    $livewire = $component->getLivewire();
                                    /** @phpstan-ignore-next-line */
                                    $data = $livewire->data;
                                    data_set($data, $prefix.'.street', $result['street']);
                                    data_set($data, $prefix.'.city', $result['city']);
                                    if (isset($result['latitude'])) {
                                        data_set($data, $prefix.'.latitude', $result['latitude']);
                                    }
                                    if (isset($result['longitude'])) {
                                        data_set($data, $prefix.'.longitude', $result['longitude']);
                                    }
                                    /** @phpstan-ignore-next-line */
                                    $livewire->data = $data;
                                }
                            })
                    ),
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
                ...self::getCoordinateFields($prefix, $showCoordinates),
            ])
            ->columns(3)
            ->columnSpanFull();
    }

    /**
     * Get coordinate fields based on visibility setting.
     *
     * @return array<int, Hidden|TextInput>
     */
    private static function getCoordinateFields(string $prefix, bool $showCoordinates): array
    {
        if ($showCoordinates) {
            return [
                TextInput::make($prefix.'.latitude')
                    ->label('Breedtegraad')
                    ->maxLength(10)
                    ->placeholder('52.123456'),
                TextInput::make($prefix.'.longitude')
                    ->label('Lengtegraad')
                    ->maxLength(9)
                    ->placeholder('4.123456'),
            ];
        }

        return [
            Hidden::make($prefix.'.latitude'),
            Hidden::make($prefix.'.longitude'),
        ];
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

        // Skip if street is already filled (don't overwrite user input)
        $street = $get($prefix.'.street');
        if ($street) {
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
        }
    }
}
