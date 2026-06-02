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
