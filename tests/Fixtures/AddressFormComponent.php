<?php

namespace Chargit\AddressComponent\Tests\Fixtures;

use Chargit\AddressComponent\AddressGroup;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Livewire\Component;

class AddressFormComponent extends Component implements HasSchemas
{
    use InteractsWithSchemas;

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    /**
     * The country the form is hydrated with. A parameter so tests can open the
     * form on the values a backend really returns — an empty string, alpha-2 —
     * instead of only on a well-formed alpha-3 code.
     */
    public function mount(string $initialCountry = 'NLD'): void
    {
        $this->form->fill([
            'address' => [
                'country' => $initialCountry,
            ],
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                AddressGroup::make('address', required: false, showCoordinates: true),
            ])
            ->statePath('data');
    }

    public function render(): string
    {
        return '<div>{{ $this->form }}</div>';
    }
}
