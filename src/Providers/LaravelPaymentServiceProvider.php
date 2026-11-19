<?php

declare(strict_types=1);

namespace Ahmed\LaravelPayment\Providers;

use Ahmed\LaravelPayment\Console\InstallPaymentPackageCommand;
use Ahmed\LaravelPayment\Core\Models\Payment;
use Ahmed\LaravelPayment\Observers\PaymentObserver;
use Ahmed\LaravelPayment\Payments\PaymentGatewayManager;
use Illuminate\Support\ServiceProvider;

final class LaravelPaymentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/payments.php', 'payments');
        $this->app->singleton(PaymentGatewayManager::class);
    }

    public function boot(): void
    {
        Payment::observe(PaymentObserver::class);

        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations/core');
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations/reconciliation');

        $this->publishes([
            __DIR__.'/../../config/payments.php' => config_path('payments.php'),
        ], 'payment-config');

        if ($this->app->runningInConsole()) {
            $this->commands([InstallPaymentPackageCommand::class]);
        }

        if (config('payments.routes.core_auto_register', true)) {
            $this->loadRoutesFrom(__DIR__.'/../../routes/core.php');
        }
    }
}
