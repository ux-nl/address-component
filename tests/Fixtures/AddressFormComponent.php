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

    public function mount(): void
    {
        $this->form->fill([
            'address' => [
                'country' => 'NLD',
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
