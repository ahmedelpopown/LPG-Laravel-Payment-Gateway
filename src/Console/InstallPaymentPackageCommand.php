<?php

declare(strict_types=1);

namespace Ahmed\LaravelPayment\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

final class InstallPaymentPackageCommand extends Command
{
    protected $signature = 'payments:install {--force : Run package migrations in production without prompting}';

    protected $description = 'Install the Payment Core and Reconciliation tables.';

    public function handle(): int
    {
        $packageRoot = dirname(__DIR__, 2);
        $defaultConnection = (string) config('database.default', 'mysql');
        $paymentConnection = (string) (config('payments.connection') ?: $defaultConnection);
        $reconciliationConnection = (string) (
            config('laravel-payment-reconciliation.database.connection')
            ?: $paymentConnection
        );

        $migrationGroups = [
            [
                'path' => $packageRoot.'/database/migrations/core',
                'connection' => $paymentConnection,
            ],
            [
                'path' => $packageRoot.'/database/migrations/reconciliation',
                'connection' => $reconciliationConnection,
            ],
        ];

        foreach ($migrationGroups as $group) {
            if (! is_dir($group['path'])) {
                $this->error("Package migration path not found: {$group['path']}");

                return self::FAILURE;
            }

            $exitCode = Artisan::call('migrate', [
                '--database' => $group['connection'],
                '--path' => [$group['path']],
                '--realpath' => true,
                '--force' => (bool) $this->option('force'),
            ]);

            $this->output->write(Artisan::output());

            if ($exitCode !== self::SUCCESS) {
                $this->error('Payment package installation failed.');

                return self::FAILURE;
            }
        }

        $this->info('Payment Core and Reconciliation tables are installed.');

        return self::SUCCESS;
    }
}
