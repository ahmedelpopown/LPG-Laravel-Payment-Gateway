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
        $migrationPaths = [
            $packageRoot.'/database/migrations/core',
            $packageRoot.'/database/migrations/reconciliation',
        ];

        foreach ($migrationPaths as $path) {
            if (! is_dir($path)) {
                $this->error("Package migration path not found: {$path}");

                return self::FAILURE;
            }
        }

        $connection = config('payments.connection') ?: config('database.default', 'mysql');
        $exitCode = Artisan::call('migrate', [
            '--database' => $connection,
            '--path' => $migrationPaths,
            '--realpath' => true,
            '--force' => (bool) $this->option('force'),
        ]);

        $this->output->write(Artisan::output());

        if ($exitCode !== self::SUCCESS) {
            $this->error('Payment package installation failed.');

            return self::FAILURE;
        }

        $this->info('Payment Core and Reconciliation tables are installed.');

        return self::SUCCESS;
    }
}
