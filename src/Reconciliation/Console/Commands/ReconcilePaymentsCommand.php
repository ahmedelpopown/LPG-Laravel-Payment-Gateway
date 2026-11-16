<?php

namespace Ahmed\LaravelPayment\Reconciliation\Console\Commands;

use Ahmed\LaravelPayment\Reconciliation\Services\ReconciliationManager;
use DateTimeImmutable;
use Illuminate\Console\Command;
use Throwable;

class ReconcilePaymentsCommand extends Command
{
    protected $signature = 'payment:reconcile
        {gateway : Gateway key, for example stripe or paypal}
        {--from= : Start date/time (ISO-8601)}
        {--to= : End date/time (ISO-8601)}
        {--chunk=100 : Number of local records per chunk}';

    protected $description = 'Compare local payment records with the payment provider.';

    public function handle(ReconciliationManager $manager): int
    {
        try {
            $from = new DateTimeImmutable((string) ($this->option('from') ?: now()->subDay()->toIso8601String()));
            $to = new DateTimeImmutable((string) ($this->option('to') ?: now()->toIso8601String()));
            $run = $manager->run((string) $this->argument('gateway'), $from, $to, max(1, (int) $this->option('chunk')));

            $summary = (array) $run->summary;
            $this->info("Reconciliation run #{$run->id}: {$run->status}");
            $this->table(
                ['Checked', 'Matched', 'Mismatched', 'Provider missing', 'Local missing', 'Manual review', 'Provider errors'],
                [[
                    $summary['total_checked'] ?? 0,
                    $summary['matched'] ?? 0,
                    $summary['mismatched'] ?? 0,
                    $summary['provider_not_found'] ?? 0,
                    $summary['local_not_found'] ?? 0,
                    $summary['manual_review'] ?? 0,
                    $summary['provider_error'] ?? 0,
                ]],
            );

            return $run->status === 'completed_with_errors'
                ? self::FAILURE
                : self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        }
    }
}
