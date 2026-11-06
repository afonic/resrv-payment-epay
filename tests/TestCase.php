<?php

namespace Reach\ResrvPaymentEpay\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Reach\ResrvPaymentEpay\Http\Payment\EpayPaymentGateway;
use Reach\ResrvPaymentEpay\ServiceProvider;
use Statamic\Addons\Manifest;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Testing\AddonTestCase;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;

abstract class TestCase extends AddonTestCase
{
    use PreventsSavingStacheItemsToDisk;
    use RefreshDatabase;

    protected string $addonServiceProvider = ServiceProvider::class;

    protected function setUp(): void
    {
        parent::setUp();

        Collection::make('pages')->routes('/{slug}')->save();

        $completed = Entry::make()->collection('pages')->slug('checkout-completed')->data(['title' => 'Checkout completed']);
        $completed->save();

        config(['resrv-config.checkout_completed_entry' => $completed->id()]);
    }

    protected function getPackageProviders($app)
    {
        return array_merge(parent::getPackageProviders($app), [
            \Livewire\LivewireServiceProvider::class,
            \Reach\StatamicResrv\StatamicResrvServiceProvider::class,
        ]);
    }

    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        // Statamic 6 moved the addon manifest from Statamic\Extend to Statamic\Addons.
        $manifest = $app->make(class_exists(Manifest::class) ? Manifest::class : \Statamic\Extend\Manifest::class);
        $manifest->manifest += [
            'reachweb/statamic-resrv' => [
                'id' => 'reachweb/statamic-resrv',
                'namespace' => 'Reach\\StatamicResrv',
                'autoload' => 'src',
                'provider' => \Reach\StatamicResrv\StatamicResrvServiceProvider::class,
            ],
        ];
    }

    protected function resolveApplicationConfiguration($app)
    {
        parent::resolveApplicationConfiguration($app);

        $app['config']->set('statamic.editions.pro', true);
        $app['config']->set('statamic.search.indexes', ['cp' => ['driver' => 'null']]);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('view.paths', [__DIR__.'/__fixtures__/views']);

        $app['config']->set('resrv-config.payment_gateways', [
            'epay' => ['class' => EpayPaymentGateway::class],
        ]);

        $app['config']->set('resrv-payment-epay', array_merge(require __DIR__.'/../config/resrv-payment-epay.php', [
            'acquirer_id' => '14',
            'merchant_id' => '2137000001',
            'pos_id' => '99999999',
            'username' => 'MERCHANT01',
            'password' => 'secret-password',
            'ticketing_url' => 'https://epay.test/services/tickets/issuer.asmx',
            'payment_url' => 'https://epay.test/redirection/pay.aspx',
        ]));
    }
}
