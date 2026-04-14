<?php

use Chargit\AddressComponent\AddressGroup;
use Chargit\AddressComponent\PostcodeLookupService;
use Filament\Schemas\Components\Fieldset;

test('make returns a fieldset component', function () {
    $fieldset = AddressGroup::make();

    expect($fieldset)->toBeInstanceOf(Fieldset::class)
        ->and($fieldset->getLabel())->toBe('Adres');
});

test('fieldset has three columns layout', function () {
    $fieldset = AddressGroup::make();

    expect($fieldset->getColumns())->toMatchArray(['lg' => 3]);
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
