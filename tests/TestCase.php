<?php

declare(strict_types=1);

namespace Ahmed\LaravelPayment\Tests;

use Ahmed\LaravelPayment\Providers\LaravelPaymentServiceProvider;
use Ahmed\LaravelPayment\Reconciliation\Providers\ReconciliationServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            LaravelPaymentServiceProvider::class,
            ReconciliationServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        $app['config']->set('payments.routes.core_auto_register', false);
        $app['config']->set('payments.paypal.client_id', 'paypal-test-client-id');
        $app['config']->set('payments.paypal.client_secret', 'paypal-test-client-secret');
        $app['config']->set('payments.paypal.webhook_id', 'WH-TEST-PAYPAL');
        $app['config']->set('payments.paypal.mode', 'sandbox');
    }
}
