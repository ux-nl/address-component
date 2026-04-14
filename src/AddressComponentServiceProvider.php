<?php

namespace Chargit\AddressComponent;

use Illuminate\Support\ServiceProvider;

class AddressComponentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/address-component.php', 'address-component');
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/address-component.php' => config_path('address-component.php'),
        ], 'address-component-config');
    }
}
