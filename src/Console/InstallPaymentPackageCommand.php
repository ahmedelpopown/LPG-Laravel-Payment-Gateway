<?php

declare(strict_types=1);

namespace Ahmed\LaravelPayment\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

final class InstallPaymentPackageCommand extends Command
{
    protected $signature = 'payments:install
        {--with-booking : Also install the optional Booking integration migrations}
        {--force : Run package migrations in production without prompting}';

    protected $description = 'Install the Payment Core, Reconciliation, and optionally Booking integration tables.';

    public function handle(): int
    {
        $packageRoot = dirname(__DIR__, 2);
        $centralPath = $packageRoot.'/database/migrations/tenancy';
        $corePath = $packageRoot.'/database/migrations/core';
        $reconciliationPath = $packageRoot.'/database/migrations/reconciliation';
        $bookingPath = $packageRoot.'/database/migrations/booking';

        $force = (bool) $this->option('force');
        // Booking is an explicit opt-in. Legacy route flags must never
        // silently install schema that depends on application-owned tables.
        $withBooking = (bool) $this->option('with-booking')
            || (bool) config('payments.booking.enabled', false);

        if ($withBooking && ! config('payments.booking.enabled', false)
            && ! (config('payments.booking.booking_model') ?: config('payments.booking_model'))) {
            $this->warn('Booking migrations are enabled, but payments.booking_model is not configured. The database migration will still require the application bookings table.');
        }
        $connection = (string) (config('payments.connection')
            ?: config('tenancy.database.central_connection')
            ?: config('database.default', 'mysql'));
        $tenancyConfigured = class_exists(\Stancl\Tenancy\TenancyServiceProvider::class)
            && is_string(config('tenancy.database.central_connection'))
            && config('tenancy.database.central_connection') !== '';
        $tenantCommandExists = $tenancyConfigured && array_key_exists('tenants:migrate', Artisan::all());

        if ($withBooking && ! is_dir($bookingPath)) {
            $this->error('Booking integration migrations were not found.');
            return self::FAILURE;
        }

        $paths = [$corePath, $reconciliationPath];
        if ($withBooking) {
            $paths[] = $bookingPath;
        }

        if ($tenantCommandExists) {
            $this->info('Stancl Tenancy detected. Installing tenant-aware payment tables.');

            if (is_dir($centralPath)) {
                $this->info('Running central credential migrations...');
                $centralExitCode = Artisan::call('migrate', array_filter([
                    '--database' => $connection,
                    '--path' => $centralPath,
                    '--realpath' => true,
                    '--force' => $force,
                ], static fn (mixed $value): bool => $value !== null));
                $this->output->write(Artisan::output());

                if ($centralExitCode !== self::SUCCESS) {
                    return self::FAILURE;
                }
            }

            $this->info('Running Payment Core and Reconciliation migrations for tenants...');
            $tenantExitCode = Artisan::call('tenants:migrate', array_filter([
                '--path' => $paths,
                '--realpath' => true,
                '--force' => $force,
            ], static fn (mixed $value): bool => $value !== null));
            $this->output->write(Artisan::output());

            if ($tenantExitCode !== self::SUCCESS) {
                return self::FAILURE;
            }
        } else {
            $this->info('No Stancl Tenancy command found. Installing Payment Core on the default database.');
            $exitCode = Artisan::call('migrate', array_filter([
                '--database' => $connection,
                '--path' => $paths,
                '--realpath' => true,
                '--force' => $force,
            ], static fn (mixed $value): bool => $value !== null));
            $this->output->write(Artisan::output());

            if ($exitCode !== self::SUCCESS) {
                return self::FAILURE;
            }
        }

        $this->info('Payment package installation completed successfully.');
        if (! $withBooking) {
            $this->line('Booking integration was not installed. Use --with-booking only in an application that has the required booking and employee tables.');
        }

        return self::SUCCESS;
    }
}
