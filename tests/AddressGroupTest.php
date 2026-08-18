<?php

use Chargit\AddressComponent\AddressGroup;
use Chargit\AddressComponent\PostcodeLookupService;
use Filament\Schemas\Components\Grid;

test('make returns a grid component without border', function () {
    $grid = AddressGroup::make();

    expect($grid)->toBeInstanceOf(Grid::class);
});

test('grid has three columns layout', function () {
    $grid = AddressGroup::make();

    expect($grid->getColumns())->toMatchArray(['lg' => 3]);
});

function addressField(string $name)
{
    foreach (AddressGroup::make('address')->getDefaultChildComponents() as $component) {
        if (method_exists($component, 'getName') && $component->getName() === $name) {
            return $component;
        }
    }

    return null;
}

test('house number only allows digits via a numeric mask', function () {
    $houseNumber = addressField('address.houseNumber');

    expect($houseNumber)->not->toBeNull()
        ->and($houseNumber->getMask())->toBe('9999999999');
});

test('addition field has no numeric mask so letters are allowed', function () {
    $addition = addressField('address.addition');

    expect($addition)->not->toBeNull()
        ->and($addition->getMask())->toBeNull();
});

test('make accepts a closure for the required argument', function () {
    $grid = AddressGroup::make('address', required: fn (): bool => false);

    expect($grid)->toBeInstanceOf(Grid::class);
});

test('postcode field is optional when required is false', function () {
    $postalCode = null;
    foreach (AddressGroup::make('address', required: false)->getDefaultChildComponents() as $component) {
        if (method_exists($component, 'getName') && $component->getName() === 'address.postalCode') {
            $postalCode = $component;
            break;
        }
    }

    expect($postalCode)->not->toBeNull()
        ->and($postalCode->isRequired())->toBeFalse();
});

test('a complete Dutch postal code is accepted', function (string $value) {
    expect(AddressGroup::isCompleteDutchPostalCode($value))->toBeTrue();
})->with(['1234AB', '1234 ab', '5691 GD', '9999ZZ']);

test('an incomplete Dutch postal code is rejected', function (?string $value) {
    expect(AddressGroup::isCompleteDutchPostalCode($value))->toBeFalse();
})->with(['1234', '1234 ', '0234AB', 'ABCD', '', null, '123AB']);

test('postcode lookup service is called with correct parameters', function () {
    $mockService = Mockery::mock(PostcodeLookupService::class);
    $mockService->shouldReceive('lookup')
        ->once()
        ->with('1234AB', '42', 'NLD')
        ->andReturn([
            'street' => 'Teststraat',
            'city' => 'Amsterdam',
            'postalCode' => '1234AB',
            'houseNumber' => '42',
        ]);

    app()->instance(PostcodeLookupService::class, $mockService);

    $result = app(PostcodeLookupService::class)->lookup('1234AB', '42', 'NLD');

    expect($result)->toBeArray()
        ->and($result['street'])->toBe('Teststraat')
        ->and($result['city'])->toBe('Amsterdam');
});

test('normalizeCountry keeps a supported alpha-3 code', function (string $code) {
    expect(AddressGroup::normalizeCountry($code))->toBe($code);
})->with(['NLD', 'BEL', 'DEU', 'FRA', 'LUX']);

test('normalizeCountry maps alpha-2 onto alpha-3', function (string $alpha2, string $alpha3) {
    expect(AddressGroup::normalizeCountry($alpha2))->toBe($alpha3);
})->with([
    ['NL', 'NLD'],
    ['BE', 'BEL'],
    ['DE', 'DEU'],
    ['FR', 'FRA'],
    ['LU', 'LUX'],
]);

// De lege string is de waarde die een backend teruggeeft voor "geen land". Een
// `?? 'NLD'` laat hem staan, waarna de Select geen optie vindt en leeg rendert
// en de lookup het buitenlandpad in gaat.
test('normalizeCountry falls back to NLD for an empty country', function (mixed $value) {
    expect(AddressGroup::normalizeCountry($value))->toBe('NLD');
})->with([null, '', '   ']);

test('normalizeCountry falls back to NLD for a country the select cannot render', function (mixed $value) {
    expect(AddressGroup::normalizeCountry($value))->toBe('NLD');
})->with(['Netherlands', 'Nederland', 'XX', 'ESP', 0]);

test('normalizeCountry ignores case and surrounding whitespace', function () {
    expect(AddressGroup::normalizeCountry(' nl '))->toBe('NLD')
        ->and(AddressGroup::normalizeCountry('bel'))->toBe('BEL');
});

test('normalizeCountry never returns a code that is missing from the select', function (mixed $value) {
    expect(AddressGroup::COUNTRIES)->toHaveKey(AddressGroup::normalizeCountry($value));
})->with([null, '', 'NL', 'NLD', 'BE', 'XX', 'Netherlands', 0]);
