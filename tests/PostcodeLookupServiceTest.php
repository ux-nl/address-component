<?php

use Baspa\ZipCodeLocationLookup\ZipCodeLocationLookup;
use Chargit\AddressComponent\PostcodeLookupService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    // Spy on Log to verify logging behavior
    Log::spy();
});

test('lookup returns address data for valid postcode and house number', function () {
    // Mock ZipCodeLocationLookup to return valid data
    $mockLookup = Mockery::mock(ZipCodeLocationLookup::class);
    $mockLookup->shouldReceive('lookup')
        ->once()
        ->with('1234AB', 1)
        ->andReturn([
            'street' => 'Teststraat',
            'city' => 'Amsterdam',
        ]);

    $service = new PostcodeLookupService($mockLookup);
    $result = $service->lookup('1234AB', '1');

    expect($result)->toBeArray()
        ->and($result)->toHaveKeys(['street', 'city', 'postalCode', 'houseNumber'])
        ->and($result['street'])->toBe('Teststraat')
        ->and($result['city'])->toBe('Amsterdam')
        ->and($result['postalCode'])->toBe('1234AB')
        ->and($result['houseNumber'])->toBe('1');

    // No warnings or errors logged on success
    Log::shouldNotHaveReceived('warning');
    Log::shouldNotHaveReceived('error');
});

test('lookup returns null when address not found', function () {
    // Mock ZipCodeLocationLookup to return empty result
    $mockLookup = Mockery::mock(ZipCodeLocationLookup::class);
    $mockLookup->shouldReceive('lookup')
        ->once()
        ->andReturn([]);

    $service = new PostcodeLookupService($mockLookup);
    $result = $service->lookup('9999ZZ', '999');

    expect($result)->toBeNull();

    // Assert warning logged with context
    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(function ($message, $context) {
            return $message === 'Postcode lookup returned no results'
                && $context['postalCode'] === '9999ZZ'
                && $context['houseNumber'] === '999';
        });
});

test('lookup returns null when result missing required fields', function () {
    // Mock ZipCodeLocationLookup to return result without required fields
    $mockLookup = Mockery::mock(ZipCodeLocationLookup::class);
    $mockLookup->shouldReceive('lookup')
        ->once()
        ->andReturn(['street' => 'Teststraat']);  // Missing 'city'

    $service = new PostcodeLookupService($mockLookup);
    $result = $service->lookup('1234AB', '1');

    expect($result)->toBeNull();

    // Assert warning logged
    Log::shouldHaveReceived('warning')
        ->once()
        ->with('Postcode lookup returned no results', Mockery::type('array'));
});

test('lookup returns null and logs error on exception', function () {
    // Mock ZipCodeLocationLookup to throw exception
    $mockLookup = Mockery::mock(ZipCodeLocationLookup::class);
    $mockLookup->shouldReceive('lookup')
        ->once()
        ->andThrow(new Exception('API connection failed'));

    $service = new PostcodeLookupService($mockLookup);
    $result = $service->lookup('1234AB', '1');

    expect($result)->toBeNull();

    // Assert error logged with exception details
    Log::shouldHaveReceived('error')
        ->once()
        ->withArgs(function ($message, $context) {
            return $message === 'Postcode lookup failed with exception'
                && $context['postalCode'] === '1234AB'
                && $context['houseNumber'] === '1'
                && isset($context['exception'])
                && isset($context['trace']);
        });
});

test('lookup cleans postcode input by removing spaces and uppercasing', function () {
    $mockLookup = Mockery::mock(ZipCodeLocationLookup::class);
    $mockLookup->shouldReceive('lookup')
        ->once()
        ->with('1234AB', 1)  // Expects cleaned format
        ->andReturn(['street' => 'Test', 'city' => 'City']);

    $service = new PostcodeLookupService($mockLookup);
    $result = $service->lookup('1234 ab', '1');  // Input with space and lowercase

    expect($result['postalCode'])->toBe('1234AB');  // Cleaned output
});

test('lookup converts house number to integer', function () {
    $mockLookup = Mockery::mock(ZipCodeLocationLookup::class);
    $mockLookup->shouldReceive('lookup')
        ->once()
        ->with('1234AB', 42)  // Expects integer
        ->andReturn(['street' => 'Test', 'city' => 'City']);

    $service = new PostcodeLookupService($mockLookup);
    $result = $service->lookup('1234AB', '42');  // Input as string

    expect($result)->not->toBeNull();
});

test('lookup uses google maps geocoding for foreign addresses', function () {
    Http::fake([
        'maps.googleapis.com/maps/api/geocode/*' => Http::response([
            'status' => 'OK',
            'results' => [[
                'geometry' => ['location' => ['lat' => 50.8503, 'lng' => 4.3517]],
                'address_components' => [
                    ['types' => ['route'], 'long_name' => 'Rue de la Loi'],
                    ['types' => ['locality'], 'long_name' => 'Bruxelles'],
                    ['types' => ['country'], 'long_name' => 'Belgium'],
                    ['types' => ['postal_code'], 'long_name' => '1000'],
                ],
            ]],
        ]),
    ]);

    $service = app(PostcodeLookupService::class);
    $result = $service->lookup('1000', '1', 'BEL');

    expect($result)->toBeArray()
        ->and($result['street'])->toBe('Rue de la Loi')
        ->and($result['city'])->toBe('Bruxelles')
        ->and($result['latitude'])->toBe('50.8503')
        ->and($result['longitude'])->toBe('4.3517')
        ->and($result['postalCode'])->toBe('1000')
        ->and($result['houseNumber'])->toBe('1');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'maps.googleapis.com')
            && str_contains($request->url(), 'components=country%3ABE');
    });
});

test('lookup uses google maps geocoding for german addresses', function () {
    Http::fake([
        'maps.googleapis.com/maps/api/geocode/*' => Http::response([
            'status' => 'OK',
            'results' => [[
                'geometry' => ['location' => ['lat' => 52.5200, 'lng' => 13.4050]],
                'address_components' => [
                    ['types' => ['route'], 'long_name' => 'Unter den Linden'],
                    ['types' => ['locality'], 'long_name' => 'Berlin'],
                    ['types' => ['country'], 'long_name' => 'Germany'],
                    ['types' => ['postal_code'], 'long_name' => '10117'],
                ],
            ]],
        ]),
    ]);

    $service = app(PostcodeLookupService::class);
    $result = $service->lookup('10117', '1', 'DEU');

    expect($result)->toBeArray()
        ->and($result['street'])->toBe('Unter den Linden')
        ->and($result['city'])->toBe('Berlin')
        ->and($result['latitude'])->toBe('52.52')
        ->and($result['longitude'])->toBe('13.405');
});

test('lookup returns null for unsupported country', function () {
    $service = app(PostcodeLookupService::class);
    $result = $service->lookup('12345', '1', 'USA');

    expect($result)->toBeNull();

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn ($message) => $message === 'Unsupported country code for geocoding');
});

test('lookup returns null when google maps returns no results for foreign address', function () {
    Http::fake([
        'maps.googleapis.com/maps/api/geocode/*' => Http::response([
            'status' => 'ZERO_RESULTS',
            'results' => [],
        ]),
    ]);

    $service = app(PostcodeLookupService::class);
    $result = $service->lookup('00000', '1', 'BEL');

    expect($result)->toBeNull();

    Log::shouldHaveReceived('warning')
        ->once()
        ->with('Google Maps geocoding returned no results', Mockery::type('array'));
});

test('geocodeCoordinates geocodes the full address when the postcode is incomplete', function () {
    Http::fake([
        'maps.googleapis.com/maps/api/geocode/*' => Http::response([
            'status' => 'OK',
            'results' => [[
                'geometry' => ['location' => ['lat' => 52.3676, 'lng' => 4.9041]],
            ]],
        ]),
    ]);

    $service = app(PostcodeLookupService::class);
    $result = $service->geocodeCoordinates([
        'street' => 'Damrak',
        'houseNumber' => '1',
        'postalCode' => '1012',
        'city' => 'Amsterdam',
        'country' => 'NLD',
    ]);

    expect($result)->toBeArray()
        ->and($result['latitude'])->toBe('52.3676')
        ->and($result['longitude'])->toBe('4.9041');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'maps.googleapis.com')
            && str_contains($request->url(), 'components=country%3ANL')
            && str_contains(urldecode($request->url()), 'Damrak 1');
    });
});

test('geocodeCoordinates returns null when address is empty', function () {
    $service = app(PostcodeLookupService::class);
    $result = $service->geocodeCoordinates([
        'country' => 'NLD',
    ]);

    expect($result)->toBeNull();
});

test('geocodeCoordinates returns null when google maps returns no results', function () {
    Http::fake([
        'maps.googleapis.com/maps/api/geocode/*' => Http::response([
            'status' => 'ZERO_RESULTS',
            'results' => [],
        ]),
    ]);

    $service = app(PostcodeLookupService::class);
    $result = $service->geocodeCoordinates([
        'street' => 'Onbekend',
        'houseNumber' => '999',
        'city' => 'Nergens',
        'country' => 'NLD',
    ]);

    expect($result)->toBeNull();

    Log::shouldHaveReceived('warning')
        ->once()
        ->with('Google Maps coordinate lookup returned no results', Mockery::type('array'));
});

test('lookup for NLD still uses the existing postcode.tech flow', function () {
    $mockLookup = Mockery::mock(ZipCodeLocationLookup::class);
    $mockLookup->shouldReceive('lookup')
        ->once()
        ->with('1234AB', 1)
        ->andReturn([
            'street' => 'Teststraat',
            'city' => 'Amsterdam',
            'lat' => 52.3676,
            'lng' => 4.9041,
        ]);

    $service = new PostcodeLookupService($mockLookup);
    $result = $service->lookup('1234AB', '1', 'NLD');

    expect($result)->toBeArray()
        ->and($result['street'])->toBe('Teststraat')
        ->and($result['city'])->toBe('Amsterdam');
});

// De stille bug: valt de lookup terug op Google en levert die een
// postcodecentroïde zonder `route`, dan komt de straat als lege string terug.
// `isset('')` is true, dus die glipte door de guard en maakte het straatveld
// leeg in plaats van het te vullen — zonder melding en zonder logregel.
test('lookup returns null when the address comes back without a street or city', function (array $result) {
    $mockLookup = Mockery::mock(ZipCodeLocationLookup::class);
    $mockLookup->shouldReceive('lookup')->once()->andReturn($result);

    $service = new PostcodeLookupService($mockLookup);

    expect($service->lookup('4921JN', '20'))->toBeNull();

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn ($message) => $message === 'Postcode lookup returned no results');
})->with([
    'lege straat' => [['street' => '', 'city' => 'Made']],
    'lege plaats' => [['street' => 'Zilverschoon', 'city' => '']],
    'beide leeg' => [['street' => '', 'city' => '']],
    'alleen spaties' => [['street' => '   ', 'city' => 'Made']],
]);

// Hetzelfde patroon in het buitenlandpad. Dat pad komt niet langs de guard in
// lookup(), dus het gaf een lege straat ongefilterd terug.
test('foreign lookup returns null when google returns a centroid without a street', function () {
    Http::fake([
        'maps.googleapis.com/maps/api/geocode/*' => Http::response([
            'status' => 'OK',
            'results' => [[
                'geometry' => ['location' => ['lat' => 50.8503, 'lng' => 4.3517]],
                'address_components' => [
                    ['types' => ['locality', 'political'], 'long_name' => 'Bruxelles'],
                    ['types' => ['postal_code'], 'long_name' => '1000'],
                ],
            ]],
        ]),
    ]);

    expect(app(PostcodeLookupService::class)->lookup('1000', '1', 'BEL'))->toBeNull();

    Log::shouldHaveReceived('warning')
        ->once()
        ->with('Google Maps geocoding returned no usable address', Mockery::type('array'));
});

// Google zet de types in willekeurige volgorde; op alleen `types[0]` lezen liet
// een `locality` die achteraan stond wegvallen.
test('foreign lookup reads a component type that is not listed first', function () {
    Http::fake([
        'maps.googleapis.com/maps/api/geocode/*' => Http::response([
            'status' => 'OK',
            'results' => [[
                'geometry' => ['location' => ['lat' => 50.8503, 'lng' => 4.3517]],
                'address_components' => [
                    ['types' => ['political', 'locality'], 'long_name' => 'Bruxelles'],
                    ['types' => ['route'], 'long_name' => 'Rue de la Loi'],
                ],
            ]],
        ]),
    ]);

    $result = app(PostcodeLookupService::class)->lookup('1000', '1', 'BEL');

    expect($result)->not->toBeNull()
        ->and($result['city'])->toBe('Bruxelles');
});

// Kleinere kernen leveren geen `locality`, waardoor de plaats leeg bleef en het
// hele resultaat als onbruikbaar werd weggegooid.
test('foreign lookup falls back to postal_town for the city', function () {
    Http::fake([
        'maps.googleapis.com/maps/api/geocode/*' => Http::response([
            'status' => 'OK',
            'results' => [[
                'geometry' => ['location' => ['lat' => 51.2093, 'lng' => 3.2247]],
                'address_components' => [
                    ['types' => ['route'], 'long_name' => 'Markt'],
                    ['types' => ['postal_town'], 'long_name' => 'Brugge'],
                ],
            ]],
        ]),
    ]);

    $result = app(PostcodeLookupService::class)->lookup('8000', '1', 'BEL');

    expect($result)->not->toBeNull()
        ->and($result['city'])->toBe('Brugge');
});

// Regressie: de knop geocodeerde het adres als vrije tekst en gaf daarmee een
// andere GPS locatie dan het automatisch invullen, dat het adresregister
// gebruikt. Voor hetzelfde adres moeten beide hetzelfde antwoord geven.
test('geocodeCoordinates resolves a Dutch address through the same source as the automatic lookup', function () {
    Http::fake([
        'postcode.tech/*' => Http::response([
            'postcode' => '4921JN',
            'number' => 20,
            'street' => 'Zilverschoon',
            'city' => 'Made',
            'municipality' => 'Drimmelen',
            'province' => 'Noord-Brabant',
            'geo' => ['lat' => 51.6775, 'lon' => 4.7817],
        ]),
        // Google geeft voor dit adres 51.6774583, 4.7817044 — ruim naast het
        // punt uit het register. De knop mag daar niet meer langs.
        'maps.googleapis.com/*' => Http::response([
            'status' => 'OK',
            'results' => [[
                'formatted_address' => 'Zilverschoon 20, 4921 JN Made, Netherlands',
                'geometry' => ['location' => ['lat' => 51.6774583, 'lng' => 4.7817044]],
                'address_components' => [],
            ]],
        ]),
    ]);

    $service = app(PostcodeLookupService::class);

    $automatic = $service->lookup('4921 JN', '20', 'NLD');
    $button = $service->geocodeCoordinates([
        'street' => 'Zilverschoon',
        'houseNumber' => '20',
        'postalCode' => '4921 JN',
        'city' => 'Made',
        'country' => 'NLD',
    ]);

    expect($button)->toBe([
        'latitude' => $automatic['latitude'],
        'longitude' => $automatic['longitude'],
    ])
        ->and($button['latitude'])->toBe('51.6775')
        ->and($button['longitude'])->toBe('4.7817');

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'maps.googleapis.com'));
});

test('geocodeCoordinates returns null when the postcode lookup does not know the address', function () {
    Http::fake([
        'postcode.tech/*' => Http::response(['message' => 'No result for this combination.'], 404),
        'maps.googleapis.com/*' => Http::response([
            'status' => 'OK',
            'results' => [[
                'geometry' => ['location' => ['lat' => 52.3676, 'lng' => 4.9041]],
                'address_components' => [],
            ]],
        ]),
    ]);

    $service = app(PostcodeLookupService::class);

    // Het adres bestaat niet in het register en het Google-fallbackpad in de
    // lookup levert geen straat, dus de knop hoort niets te vullen in plaats
    // van een coördinaat van een ander pand.
    expect($service->geocodeCoordinates([
        'street' => 'Onbekend',
        'houseNumber' => '999',
        'postalCode' => '9999ZZ',
        'city' => 'Nergens',
        'country' => 'NLD',
    ]))->toBeNull();
});

test('geocodeCoordinates returns null when the postcode lookup finds an address without coordinates', function () {
    $mockLookup = Mockery::mock(ZipCodeLocationLookup::class);
    $mockLookup->shouldReceive('lookup')
        ->once()
        ->with('1234AB', 1)
        ->andReturn(['street' => 'Teststraat', 'city' => 'Amsterdam']);

    $service = new PostcodeLookupService($mockLookup);

    expect($service->geocodeCoordinates([
        'street' => 'Teststraat',
        'houseNumber' => '1',
        'postalCode' => '1234AB',
        'city' => 'Amsterdam',
        'country' => 'NLD',
    ]))->toBeNull();

    Log::shouldHaveReceived('warning')
        ->once()
        ->with('Postcode lookup returned an address without coordinates', Mockery::type('array'));
});

test('geocodeCoordinates geocodes the full address when the postcode lookup cannot be used', function (array $address) {
    Http::fake([
        'maps.googleapis.com/maps/api/geocode/*' => Http::response([
            'status' => 'OK',
            'results' => [[
                'geometry' => ['location' => ['lat' => 50.8503, 'lng' => 4.3517]],
                'address_components' => [],
            ]],
        ]),
    ]);

    $result = app(PostcodeLookupService::class)->geocodeCoordinates($address);

    expect($result['latitude'])->toBe('50.8503');

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'postcode.tech'));
})->with([
    // Buitenland heeft geen register, dus de vrije-tekstquery met straat en
    // woonplaats is daar het beste dat er is.
    'buitenland' => [[
        'street' => 'Grote Markt',
        'houseNumber' => '1',
        'postalCode' => '1000',
        'city' => 'Brussel',
        'country' => 'BEL',
    ]],
    'geen huisnummer' => [[
        'street' => 'Grote Markt',
        'postalCode' => '1012AB',
        'city' => 'Amsterdam',
        'country' => 'NLD',
    ]],
    'geen postcode' => [[
        'street' => 'Grote Markt',
        'houseNumber' => '1',
        'city' => 'Amsterdam',
        'country' => 'NLD',
    ]],
]);
