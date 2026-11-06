<?php

namespace Reach\ResrvPaymentEpay;

use Statamic\Providers\AddonServiceProvider;

class ServiceProvider extends AddonServiceProvider
{
    protected $routes = [
        'web' => __DIR__.'/../routes/web.php',
    ];

    public function register(): void
    {
        parent::register();

        $this->mergeConfigFrom(__DIR__.'/../config/resrv-payment-epay.php', 'resrv-payment-epay');
    }

    public function boot()
    {
        parent::boot();

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'resrv-payment-epay');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->publishes([
            __DIR__.'/../config/resrv-payment-epay.php' => config_path('resrv-payment-epay.php'),
        ], 'resrv-payment-epay-config');

        $this->publishes([
            __DIR__.'/../resources/views/livewire' => resource_path('views/vendor/resrv-payment-epay/livewire'),
        ], 'resrv-checkout-views-epay');
    }
}
