<?php

use Chargit\AddressComponent\PostcodeLookupService;
use Chargit\AddressComponent\Tests\Fixtures\AddressFormComponent;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/**
 * Mock the lookup service to return the given results in order, one per
 * lookup; the last one repeats. A chain of andReturn() calls would not do
 * that: Mockery keeps only the last value, so every lookup got the same
 * result.
 */
function mockLookup(array|null ...$results): void
{
    $mock = Mockery::mock(PostcodeLookupService::class);
    $mock->shouldReceive('lookup')->andReturn(...$results);

    app()->instance(PostcodeLookupService::class, $mock);
}

function lookupResult(string $street, string $city, ?string $lat, ?string $lng): array
{
    return [
        'street' => $street,
        'city' => $city,
        'postalCode' => '1234AB',
        'houseNumber' => '42',
        'latitude' => $lat,
        'longitude' => $lng,
    ];
}

test('auto lookup fills GPS coordinates for a known address', function () {
    mockLookup(lookupResult('Teststraat', 'Amsterdam', '52.123456', '4.123456'));

    Livewire::test(AddressFormComponent::class)
        ->set('data.address.postalCode', '1234AB')
        ->set('data.address.houseNumber', '42')
        ->assertSet('data.address.street', 'Teststraat')
        ->assertSet('data.address.city', 'Amsterdam')
        ->assertSet('data.address.latitude', '52.123456')
        ->assertSet('data.address.longitude', '4.123456')
        ->assertSet('data.address.coordinates', '52.123456, 4.123456');
});

test('changing to another known address replaces the GPS coordinates', function () {
    mockLookup(
        lookupResult('Teststraat', 'Amsterdam', '52.123456', '4.123456'),
        lookupResult('Dorpsstraat', 'Utrecht', '52.654321', '5.654321'),
    );

    Livewire::test(AddressFormComponent::class)
        ->set('data.address.postalCode', '1234AB')
        ->set('data.address.houseNumber', '42')
        ->set('data.address.postalCode', '5678CD')
        ->assertSet('data.address.street', 'Dorpsstraat')
        ->assertSet('data.address.latitude', '52.654321')
        ->assertSet('data.address.longitude', '5.654321')
        ->assertSet('data.address.coordinates', '52.654321, 5.654321');
});

test('clearing the postal code clears the GPS coordinates', function () {
    mockLookup(lookupResult('Teststraat', 'Amsterdam', '52.123456', '4.123456'));

    Livewire::test(AddressFormComponent::class)
        ->set('data.address.postalCode', '1234AB')
        ->set('data.address.houseNumber', '42')
        ->set('data.address.coordinates', '52.123456, 4.123456')
        ->set('data.address.postalCode', '')
        ->assertSet('data.address.latitude', null)
        ->assertSet('data.address.longitude', null)
        ->assertSet('data.address.coordinates', null);
});

test('clearing the house number clears the GPS coordinates but keeps street and city', function () {
    mockLookup(lookupResult('Teststraat', 'Amsterdam', '52.123456', '4.123456'));

    Livewire::test(AddressFormComponent::class)
        ->set('data.address.postalCode', '1234AB')
        ->set('data.address.houseNumber', '42')
        ->set('data.address.coordinates', '52.123456, 4.123456')
        ->set('data.address.houseNumber', '')
        ->assertSet('data.address.latitude', null)
        ->assertSet('data.address.longitude', null)
        ->assertSet('data.address.coordinates', null)
        ->assertSet('data.address.street', 'Teststraat')
        ->assertSet('data.address.city', 'Amsterdam');
});

test('a failed lookup clears the stale GPS coordinates of the previous address', function () {
    mockLookup(
        lookupResult('Teststraat', 'Amsterdam', '52.123456', '4.123456'),
        null,
    );

    Livewire::test(AddressFormComponent::class)
        ->set('data.address.postalCode', '1234AB')
        ->set('data.address.houseNumber', '42')
        ->set('data.address.coordinates', '52.123456, 4.123456')
        ->set('data.address.houseNumber', '99')
        ->assertSet('data.address.latitude', null)
        ->assertSet('data.address.longitude', null)
        ->assertSet('data.address.coordinates', null);
});

// Regressie (UAT): een bestaand huisnummer vulde straat en plaats, en na het
// wijzigen naar een niet-bestaand huisnummer bleven die staan — en werden ze
// opgeslagen bij het adres dat niet bestaat.
test('a failed lookup clears the street and city the previous lookup filled in', function () {
    mockLookup(
        lookupResult('Dam', 'Amsterdam', '52.373', '4.893'),
        null,
    );

    Livewire::test(AddressFormComponent::class)
        ->set('data.address.postalCode', '1012JS')
        ->set('data.address.houseNumber', '1')
        ->assertSet('data.address.street', 'Dam')
        ->set('data.address.houseNumber', '999')
        ->assertSet('data.address.street', null)
        ->assertSet('data.address.city', null);
});

// Regressie (UAT): eerst een niet-bestaand en daarna een bestaand huisnummer
// moet het adres alsnog invullen.
test('a known address after a failed lookup fills street and city', function () {
    mockLookup(
        null,
        lookupResult('Dam', 'Amsterdam', '52.373', '4.893'),
    );

    Livewire::test(AddressFormComponent::class)
        ->set('data.address.postalCode', '1012JS')
        ->set('data.address.houseNumber', '999')
        ->assertSet('data.address.street', null)
        ->set('data.address.houseNumber', '1')
        ->assertSet('data.address.street', 'Dam')
        ->assertSet('data.address.city', 'Amsterdam');
});

test('a failed lookup keeps a street and city the customer typed themselves', function () {
    mockLookup(
        lookupResult('Dam', 'Amsterdam', '52.373', '4.893'),
        null,
    );

    Livewire::test(AddressFormComponent::class)
        ->set('data.address.postalCode', '1012JS')
        ->set('data.address.houseNumber', '1')
        ->set('data.address.street', 'Nieuwe Nieuwstraat')
        ->set('data.address.houseNumber', '999')
        ->assertSet('data.address.street', 'Nieuwe Nieuwstraat')
        ->assertSet('data.address.city', null);
});

test('a failed lookup keeps an address that was not filled in by a lookup', function () {
    mockLookup(null);

    Livewire::test(AddressFormComponent::class)
        ->fillForm(['address' => ['street' => 'Dam', 'city' => 'Amsterdam', 'country' => 'NLD']])
        ->set('data.address.postalCode', '1012JS')
        ->set('data.address.houseNumber', '999')
        ->assertSet('data.address.street', 'Dam')
        ->assertSet('data.address.city', 'Amsterdam');
});

test('the remembered lookup result is not part of the saved address', function () {
    mockLookup(lookupResult('Dam', 'Amsterdam', '52.373', '4.893'));

    Livewire::test(AddressFormComponent::class)
        ->set('data.address.postalCode', '1012JS')
        ->set('data.address.houseNumber', '1')
        ->call('save')
        ->assertSet('saved.address.street', 'Dam')
        ->assertSet('saved.address', fn (array $address): bool => ! array_key_exists('lookupStreet', $address)
            && ! array_key_exists('lookupCity', $address));
});

test('a lookup without coordinates clears the stale GPS coordinates', function () {
    mockLookup(
        lookupResult('Teststraat', 'Amsterdam', '52.123456', '4.123456'),
        lookupResult('Dorpsstraat', 'Utrecht', null, null),
    );

    Livewire::test(AddressFormComponent::class)
        ->set('data.address.postalCode', '1234AB')
        ->set('data.address.houseNumber', '42')
        ->set('data.address.coordinates', '52.123456, 4.123456')
        ->set('data.address.postalCode', '5678CD')
        ->assertSet('data.address.street', 'Dorpsstraat')
        ->assertSet('data.address.latitude', null)
        ->assertSet('data.address.longitude', null)
        ->assertSet('data.address.coordinates', null);
});

// Regressie: een backend die "geen land" als lege string teruggeeft liet het
// veld leeg renderen, omdat de Select geen optie heeft die daarop matcht en
// `default('NLD')` niet ingrijpt op een gevuld formulier.
test('the country field opens on a code the select can render', function (string $initialCountry, string $expected) {
    Livewire::test(AddressFormComponent::class, ['initialCountry' => $initialCountry])
        ->assertSet('data.address.country', $expected);
})->with([
    'lege string' => ['', 'NLD'],
    'alpha-2' => ['NL', 'NLD'],
    'onbekend' => ['Netherlands', 'NLD'],
    'geldig' => ['BEL', 'BEL'],
]);

// Dezelfde lege string stuurde de lookup het buitenlandpad in, waar een
// Nederlands adres nooit gevonden wordt.
test('a country the select cannot render still looks up as a Dutch address', function (string $initialCountry) {
    $mock = Mockery::mock(PostcodeLookupService::class);
    // Het veld zet een Nederlandse postcode als '1234 AB'; zo gaat hij ook
    // naar de lookup, die de spatie zelf weer weghaalt.
    $mock->shouldReceive('lookup')
        ->with('1234 AB', '42', 'NLD')
        ->andReturn(lookupResult('Teststraat', 'Amsterdam', '52.123456', '4.123456'));

    app()->instance(PostcodeLookupService::class, $mock);

    Livewire::test(AddressFormComponent::class, ['initialCountry' => $initialCountry])
        ->set('data.address.postalCode', '1234AB')
        ->set('data.address.houseNumber', '42')
        ->assertSet('data.address.street', 'Teststraat')
        ->assertSet('data.address.city', 'Amsterdam');
})->with(['', 'NL', 'Netherlands']);

// Regressie: de GPS-knop geocodeerde het adres als vrije tekst bij Google,
// terwijl het automatisch invullen het adresregister gebruikt. Voor hetzelfde
// adres leverde dat twee verschillende locaties op — voor Dam 1 in Amsterdam
// ruim honderd meter uit elkaar.
test('the GPS button returns the same location the automatic lookup filled in', function () {
    Http::fake([
        'postcode.tech/*' => Http::response([
            'postcode' => '4921JN',
            'number' => 20,
            'street' => 'Zilverschoon',
            'city' => 'Made',
            'geo' => ['lat' => 51.6775, 'lon' => 4.7817],
        ]),
        'maps.googleapis.com/*' => Http::response([
            'status' => 'OK',
            'results' => [[
                'formatted_address' => 'Zilverschoon 20, 4921 JN Made, Netherlands',
                'geometry' => ['location' => ['lat' => 51.6774583, 'lng' => 4.7817044]],
                'address_components' => [],
            ]],
        ]),
    ]);

    Livewire::test(AddressFormComponent::class)
        ->set('data.address.postalCode', '4921JN')
        ->set('data.address.houseNumber', '20')
        ->assertSet('data.address.coordinates', '51.6775, 4.7817')
        ->set('data.address.coordinates', '')
        ->assertSet('data.address.latitude', null)
        ->callAction(TestAction::make('fetchCoordinates')->schemaComponent('address.coordinates'))
        ->assertSet('data.address.latitude', '51.6775')
        ->assertSet('data.address.longitude', '4.7817')
        ->assertSet('data.address.coordinates', '51.6775, 4.7817');
});

test('a pasted postcode is cleaned up in the field and still looked up', function () {
    $mock = Mockery::mock(PostcodeLookupService::class);
    $mock->shouldReceive('lookup')
        ->once()
        ->with('1234 AB', '42', 'NLD')
        ->andReturn(lookupResult('Teststraat', 'Amsterdam', '52.123456', '4.123456'));
    app()->instance(PostcodeLookupService::class, $mock);

    Livewire::test(AddressFormComponent::class)
        ->set('data.address.houseNumber', '42')
        ->set('data.address.postalCode', "1234\u{00A0}ab")
        ->assertSet('data.address.postalCode', '1234 AB')
        ->assertSet('data.address.street', 'Teststraat');
});

test('a pasted postcode passes validation and is saved cleaned up', function (string $value) {
    mockLookup(null);

    // fillForm() slaat de updated-hooks over, zoals ook een formulier dat
    // zonder blur wordt verstuurd: de validatie en het opslaan moeten de
    // waarde dan zelf opschonen.
    Livewire::test(AddressFormComponent::class)
        ->fillForm(['address' => ['postalCode' => $value, 'country' => 'NLD']])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertSet('saved.address.postalCode', '1234 AB');
})->with([
    'non-breaking space' => "1234\u{00A0}AB",
    'hyphen' => '1234-AB',
    'zero-width space' => "\u{200B}1234AB",
]);

test('an incomplete Dutch postcode is still rejected after cleaning it up', function () {
    mockLookup(null);

    Livewire::test(AddressFormComponent::class)
        ->fillForm(['address' => ['postalCode' => "1234\u{00A0}A", 'country' => 'NLD']])
        ->call('save')
        ->assertHasFormErrors(['address.postalCode']);
});

test('a foreign postcode keeps its own format', function () {
    mockLookup(null);

    Livewire::test(AddressFormComponent::class)
        ->fillForm(['address' => ['postalCode' => "SW1A\u{00A0}1AA", 'country' => 'FRA']])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertSet('saved.address.postalCode', 'SW1A 1AA');
});

test('a prefilled postcode opens in the same format it is saved in', function () {
    Livewire::test(AddressFormComponent::class)
        ->fillForm(['address' => ['postalCode' => '9423pe', 'country' => 'NLD']])
        ->assertSet('data.address.postalCode', '9423 PE');
});

test('a house number with surrounding spaces passes validation and is saved without them', function (string $value) {
    mockLookup(null);

    Livewire::test(AddressFormComponent::class)
        ->fillForm(['address' => ['houseNumber' => $value, 'country' => 'NLD']])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertSet('saved.address.houseNumber', '42');
})->with([
    'trailing space' => '42 ',
    'non-breaking space' => "\u{00A0}42",
    'zero-width space' => "42\u{200B}",
]);

test('a house number with letters is still rejected after cleaning it up', function () {
    mockLookup(null);

    Livewire::test(AddressFormComponent::class)
        ->fillForm(['address' => ['houseNumber' => '42 a', 'country' => 'NLD']])
        ->call('save')
        ->assertHasFormErrors(['address.houseNumber']);
});
