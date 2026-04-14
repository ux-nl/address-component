<?php

namespace Chargit\AddressComponent\Tests;

use Chargit\AddressComponent\AddressComponentServiceProvider;
use Filament\FilamentServiceProvider;
use Filament\Forms\FormsServiceProvider;
use Filament\Notifications\NotificationsServiceProvider;
use Filament\Support\SupportServiceProvider;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            LivewireServiceProvider::class,
            SupportServiceProvider::class,
            FilamentServiceProvider::class,
            FormsServiceProvider::class,
            NotificationsServiceProvider::class,
            AddressComponentServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('address-component.google_api_key', 'test-google-api-key');
        $app['config']->set('address-component.postcode_tech_api_key', 'test-postcode-tech-key');
        $app['config']->set('services.postcode_tech.api_key', 'test-postcode-tech-key');
        $app['config']->set('services.google.api_key', 'test-google-api-key');
    }
}
