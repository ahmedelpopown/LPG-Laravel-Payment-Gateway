<?php

declare(strict_types=1);

namespace Ahmed\LaravelPayment\Reconciliation\Providers;

use Ahmed\LaravelPayment\Reconciliation\Contracts\GatewayCredentialsResolver;
use Ahmed\LaravelPayment\Reconciliation\Contracts\LocalPaymentRepository;
use Ahmed\LaravelPayment\Reconciliation\Contracts\ReconciliationResolver;
use Ahmed\LaravelPayment\Reconciliation\Console\Commands\ReconcilePaymentsCommand;
use Ahmed\LaravelPayment\Reconciliation\Services\ConfigGatewayCredentialsResolver;
use Ahmed\LaravelPayment\Reconciliation\Services\DatabaseReconciliationResolver;
use Ahmed\LaravelPayment\Reconciliation\Services\GatewayDriverRegistry;
use Illuminate\Support\ServiceProvider;

final class ReconciliationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../../config/laravel-payment-reconciliation.php', 'laravel-payment-reconciliation');

        $this->app->singleton(GatewayCredentialsResolver::class, ConfigGatewayCredentialsResolver::class);
        $this->app->singleton(LocalPaymentRepository::class, function ($app) {
            $customRepository = config('laravel-payment-reconciliation.local_payments.repository');

            return $customRepository
                ? $app->make($customRepository)
                : $app->make(\Ahmed\LaravelPayment\Reconciliation\Repositories\EloquentLocalPaymentRepository::class);
        });
        $this->app->singleton(ReconciliationResolver::class, DatabaseReconciliationResolver::class);
        $this->app->singleton(GatewayDriverRegistry::class, function ($app) {
            $drivers = [];
            foreach ((array) config('laravel-payment-reconciliation.drivers', []) as $driverClass) {
                $drivers[] = $app->make($driverClass);
            }

            return new GatewayDriverRegistry($drivers);
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../../../config/laravel-payment-reconciliation.php' => config_path('laravel-payment-reconciliation.php'),
        ], 'laravel-payment-reconciliation-config');

        if ($this->app->runningInConsole()) {
            $this->commands([ReconcilePaymentsCommand::class]);
        }
    }
}
