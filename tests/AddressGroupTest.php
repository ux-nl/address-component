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
