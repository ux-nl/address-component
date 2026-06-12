<?php

namespace Chargit\AddressComponent\Tests;

use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Chargit\AddressComponent\AddressComponentServiceProvider;
use Filament\Actions\ActionsServiceProvider;
use Filament\FilamentServiceProvider;
use Filament\Forms\FormsServiceProvider;
use Filament\Notifications\NotificationsServiceProvider;
use Filament\Schemas\SchemasServiceProvider;
use Filament\Support\SupportServiceProvider;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        // Filament's SupportServiceProvider rebinds Livewire's DataStore to
        // DataStoreOverride. That override only behaves as a singleton when
        // Filament registers before Livewire (as in a real app, where package
        // discovery registers providers alphabetically). Keep Livewire after
        // the Filament providers here, or every store() call gets a fresh
        // (empty) DataStore and Livewire component state silently breaks.
        return [
            ActionsServiceProvider::class,
            BladeHeroiconsServiceProvider::class,
            BladeIconsServiceProvider::class,
            FilamentServiceProvider::class,
            FormsServiceProvider::class,
            NotificationsServiceProvider::class,
            SchemasServiceProvider::class,
            SupportServiceProvider::class,
            LivewireServiceProvider::class,
            AddressComponentServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('address-component.google_api_key', 'test-google-api-key');
        $app['config']->set('address-component.postcode_tech_api_key', 'test-postcode-tech-key');
        $app['config']->set('services.postcode_tech.api_key', 'test-postcode-tech-key');
        $app['config']->set('services.google.api_key', 'test-google-api-key');
    }
}
