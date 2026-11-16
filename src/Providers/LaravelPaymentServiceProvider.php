<?php

declare(strict_types=1);

namespace Ahmed\LaravelPayment\Providers;

use Ahmed\LaravelPayment\Console\InstallPaymentPackageCommand;
use Ahmed\LaravelPayment\Payments\PaymentGatewayManager;
use Illuminate\Support\Facades\Blade;
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
        $tenancyConfigured = $this->isStanclTenancyConfigured();

        // A normal Laravel app can run `migrate` directly. In a Stancl app,
        // these paths are added to tenants:migrate instead of the central DB.
        if (! $tenancyConfigured) {
            $this->loadMigrationsFrom(__DIR__.'/../../database/migrations/core');
            $this->loadMigrationsFrom(__DIR__.'/../../database/migrations/reconciliation');
        } else {
            $this->appendTenantMigrationPaths();
        }

        $this->publishes([
            __DIR__.'/../../config/payments.php' => config_path('payments.php'),
        ], 'payment-config');

        if ($this->app->runningInConsole()) {
            $this->commands([InstallPaymentPackageCommand::class]);
        }

        if (config('payments.routes.core_auto_register', true)) {
            $this->loadRoutesFrom(__DIR__.'/../../routes/core.php');
        }

        $bookingEnabled = $this->bookingIntegrationEnabled();
        $bookingModel = config('payments.booking.booking_model') ?: config('payments.booking_model');

        // The legacy Booking screens use host-app Blade components such as
        // x-ui.page-header and require Livewire. Keep them out of a standalone
        // Payment Core app so `view:cache` / `optimize` works without the host UI.
        if ($bookingEnabled && class_exists(\Livewire\Livewire::class)) {
            $this->loadViewsFrom(__DIR__.'/../../resources/views', 'payment');
            Blade::anonymousComponentPath(__DIR__.'/../../resources/views/components');
        }

        if ($bookingEnabled
            && $tenancyConfigured
            && is_string($bookingModel)
            && class_exists($bookingModel)
            && class_exists(\Stancl\Tenancy\Middleware\InitializeTenancyByDomain::class)) {
            $this->loadRoutesFrom(__DIR__.'/../../routes/tenant.php');
        }
    }

    private function isStanclTenancyConfigured(): bool
    {
        return class_exists(\Stancl\Tenancy\TenancyServiceProvider::class)
            && is_string(config('tenancy.database.central_connection'))
            && config('tenancy.database.central_connection') !== '';
    }


    private function bookingIntegrationEnabled(): bool
    {
        // Booking integration must be explicitly enabled. Legacy route flags
        // must not silently load Booking views/routes in standalone projects.
        return (bool) config('payments.booking.enabled', false);
    }

    private function appendTenantMigrationPaths(): void
    {
        if (! config()->has('tenancy.migration_parameters')) {
            return;
        }

        $parameters = config('tenancy.migration_parameters', []);
        $paths = $parameters['--path'] ?? [database_path('migrations/tenant')];
        $paths = is_array($paths) ? $paths : [$paths];

        $packagePaths = [
            realpath(__DIR__.'/../../database/migrations/core') ?: __DIR__.'/../../database/migrations/core',
            realpath(__DIR__.'/../../database/migrations/reconciliation') ?: __DIR__.'/../../database/migrations/reconciliation',
        ];

        if ($this->bookingIntegrationEnabled()) {
            $packagePaths[] = realpath(__DIR__.'/../../database/migrations/booking') ?: __DIR__.'/../../database/migrations/booking';
        }

        $parameters['--path'] = array_values(array_unique(array_merge($paths, $packagePaths)));
        config(['tenancy.migration_parameters' => $parameters]);
    }
}
