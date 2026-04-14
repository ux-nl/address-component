<?php

namespace Chargit\AddressComponent;

use Filament\Tables\Columns\TextColumn;

class LocationColumn extends TextColumn
{
    protected string $streetField = 'street';

    protected string $houseNumberField = 'houseNumber';

    protected string $houseNumberAdditionField = 'houseNumberAddition';

    protected string $cityField = 'city';

    protected function setUp(): void
    {
        parent::setUp();

        $this->state(function ($record): string {
            $street = $record->{$this->streetField} ?? '';
            $houseNumber = $record->{$this->houseNumberField} ?? '';
            $addition = $record->{$this->houseNumberAdditionField}
                ? '-'.$record->{$this->houseNumberAdditionField}
                : '';
            $city = $record->{$this->cityField} ?? '';

            $address = trim("{$street} {$houseNumber}{$addition}");

            if ($address && $city) {
                return "{$address}, {$city}";
            }

            return $address ?: $city ?: '-';
        });
    }

    public function streetField(string $field): static
    {
        $this->streetField = $field;

        return $this;
    }

    public function houseNumberField(string $field): static
    {
        $this->houseNumberField = $field;

        return $this;
    }

    public function houseNumberAdditionField(string $field): static
    {
        $this->houseNumberAdditionField = $field;

        return $this;
    }

    public function cityField(string $field): static
    {
        $this->cityField = $field;

        return $this;
    }
}
