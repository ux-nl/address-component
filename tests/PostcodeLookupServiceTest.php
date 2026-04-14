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
