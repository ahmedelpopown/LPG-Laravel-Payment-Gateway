<?php

namespace Ahmed\LaravelPayment\Reconciliation\Services;

use Ahmed\LaravelPayment\Reconciliation\Contracts\LocalPaymentRepository;
use Ahmed\LaravelPayment\Reconciliation\DTOs\LocalPaymentSnapshot;
use Ahmed\LaravelPayment\Reconciliation\DTOs\ProviderPaymentSnapshot;
use Ahmed\LaravelPayment\Reconciliation\Enums\ReconciliationRunStatus;
use Ahmed\LaravelPayment\Reconciliation\Enums\ReconciliationStatus;
use Ahmed\LaravelPayment\Reconciliation\Models\ReconciliationItem;
use Ahmed\LaravelPayment\Reconciliation\Models\ReconciliationRun;
use DateTimeInterface;
use Throwable;

final class ReconciliationManager
{
    public function __construct(
        private readonly GatewayDriverRegistry $drivers,
        private readonly LocalPaymentRepository $localPayments,
        private readonly PaymentComparator $comparator,
    ) {
    }

    public function run(
        string $gateway,
        DateTimeInterface $from,
        DateTimeInterface $to,
        int $chunkSize = 100,
    ): ReconciliationRun {
        if ($from >= $to) {
            throw new \InvalidArgumentException('The reconciliation start date must be earlier than the end date.');
        }

        $gateway = strtolower($gateway);
        $driver = $this->drivers->get($gateway);

        $run = ReconciliationRun::query()->create([
            'gateway' => $gateway,
            'status' => ReconciliationRunStatus::RUNNING->value,
            'period_from' => $from,
            'period_to' => $to,
            'started_at' => now(),
            'summary' => [],
        ]);

        $counts = [
            'total_checked' => 0,
            'matched' => 0,
            'mismatched' => 0,
            'local_not_found' => 0,
            'provider_not_found' => 0,
            'manual_review' => 0,
            'duplicate_reference' => 0,
            'provider_error' => 0,
        ];

        /** @var array<string, true> $matchedLocalIds */
        $matchedLocalIds = [];
        $processedProviderReferences = [];
        /** @var array<string, ProviderPaymentSnapshot> $providerOnlySnapshots PayPal Order captures may span pages. */
        $providerOnlySnapshots = [];
        $recordedProviderOnlyReferences = [];
        $hasErrors = false;
        $providerListingComplete = true;
        $cursor = null;

        // First pass: provider-side period search detects provider transactions with no local record.
        try {
            do {
                $page = $driver->listTransactions($from, $to, $cursor);

                foreach ($page->items as $providerPayment) {
                    $locals = $this->localPayments->findAllByProviderReference($gateway, $providerPayment->reference);

                    if ($locals === []) {
                        // Only PayPal Order aggregates need to be buffered across pages.
                        // Ordinary Stripe/Capture rows are written immediately to avoid
                        // holding an entire provider period in PHP memory.
                        $isPayPalOrderScan = $gateway === 'paypal'
                            && config('laravel-payment-reconciliation.gateways.paypal.reference_type', 'capture') === 'order';
                        if ($isPayPalOrderScan) {
                            $providerOnlySnapshots[$providerPayment->reference] = isset($providerOnlySnapshots[$providerPayment->reference])
                                ? $this->mergeProviderOnlySnapshots($providerOnlySnapshots[$providerPayment->reference], $providerPayment)
                                : $providerPayment;
                        } elseif (! isset($recordedProviderOnlyReferences[$providerPayment->reference])) {
                            $this->recordProviderOnly($run, $providerPayment);
                            $recordedProviderOnlyReferences[$providerPayment->reference] = true;
                            $counts['local_not_found']++;
                            $counts['total_checked']++;
                        }
                        continue;
                    }

                    if (isset($processedProviderReferences[$providerPayment->reference])) {
                        continue;
                    }
                    $processedProviderReferences[$providerPayment->reference] = true;

                    foreach ($locals as $local) {
                        $matchedLocalIds[$local->id] = true;
                    }

                    if (count($locals) > 1) {
                        $this->recordDuplicateReference($run, $locals, $providerPayment);
                        $counts['duplicate_reference']++;
                        $counts['total_checked']++;
                        continue;
                    }

                    $local = $locals[0];

                    try {
                        // Use a fresh single-resource lookup for matched payments (includes provider-specific details).
                        $freshProviderPayment = $driver->retrieve($providerPayment->reference);
                        if ($freshProviderPayment === null) {
                            $this->recordLocalOnly($run, $local, ReconciliationStatus::PROVIDER_NOT_FOUND);
                            $counts['provider_not_found']++;
                            $counts['total_checked']++;
                            continue;
                        }

                        $providerPayment = $freshProviderPayment;
                    } catch (Throwable $exception) {
                        $this->recordLocalError($run, $local, $exception);
                        $counts['provider_error']++;
                        $counts['total_checked']++;
                        $hasErrors = true;
                        continue;
                    }

                    $this->recordComparison($run, $local, $providerPayment, $counts);
                }

                $cursor = $page->nextCursor;
            } while ($page->hasMore && $cursor !== null);
        } catch (Throwable $exception) {
            $hasErrors = true;
            $providerListingComplete = false;
            $counts['provider_error']++;
            $run->forceFill(['last_error' => $this->safeMessage($exception)])->save();
        }

        foreach ($providerOnlySnapshots as $providerPayment) {
            if ($providerListingComplete) {
                $this->recordProviderOnly($run, $providerPayment);
                $counts['local_not_found']++;
            } else {
                // A partial report cannot establish the complete Order total. Do not
                // present a partially aggregated amount as a confirmed local-missing payment.
                $this->recordProviderScanError($run, $providerPayment);
                $counts['provider_error']++;
            }
            $counts['total_checked']++;
        }

        // Second pass: catch local records missing from the period listing (or with a missing provider reference).
        try {
            foreach ($this->localPayments->iterateForReconciliation($gateway, $from, $to, $chunkSize) as $local) {
                if (isset($matchedLocalIds[$local->id])) {
                    continue;
                }

                if (! $local->providerReference) {
                    $this->recordLocalOnly($run, $local, ReconciliationStatus::MANUAL_REVIEW, 'missing_provider_reference');
                    $counts['manual_review']++;
                    $counts['total_checked']++;
                    continue;
                }

                try {
                    $providerPayment = $driver->retrieve($local->providerReference);
                } catch (Throwable $exception) {
                    $this->recordLocalError($run, $local, $exception);
                    $counts['provider_error']++;
                    $counts['total_checked']++;
                    $hasErrors = true;
                    continue;
                }

                if (! $providerPayment) {
                    $this->recordLocalOnly($run, $local, ReconciliationStatus::PROVIDER_NOT_FOUND);
                    $counts['provider_not_found']++;
                    $counts['total_checked']++;
                    continue;
                }

                $this->recordComparison($run, $local, $providerPayment, $counts);
            }
        } catch (Throwable $exception) {
            $hasErrors = true;
            $counts['provider_error']++;
            $run->forceFill(['last_error' => $this->safeMessage($exception)])->save();
        }

        $status = $hasErrors
            ? ReconciliationRunStatus::COMPLETED_WITH_ERRORS
            : ReconciliationRunStatus::COMPLETED;

        $run->forceFill([
            'status' => $status->value,
            'completed_at' => now(),
            'summary' => $counts,
        ])->save();

        return $run->fresh();
    }

    private function recordComparison(
        ReconciliationRun $run,
        LocalPaymentSnapshot $local,
        ProviderPaymentSnapshot $provider,
        array &$counts,
    ): void {
        $comparison = $this->comparator->compare($local, $provider);

        ReconciliationItem::query()->create([
            'reconciliation_run_id' => $run->id,
            'local_payment_id' => $local->id,
            'provider_reference' => $provider->reference,
            'result' => $comparison->status->value,
            'local_status' => $local->status,
            'provider_status' => $provider->status,
            'local_amount_minor' => $local->amountMinor,
            'provider_amount_minor' => $provider->amountMinor,
            'local_refunded_amount_minor' => $local->refundedAmountMinor,
            'provider_refunded_amount_minor' => $provider->refundedAmountMinor,
            'local_currency' => strtoupper($local->currency),
            'provider_currency' => strtoupper($provider->currency),
            'differences' => $comparison->differences,
            'provider_snapshot' => $provider->raw,
        ]);

        $counts['total_checked']++;
        if ($comparison->status === ReconciliationStatus::MATCHED) {
            $counts['matched']++;
        } else {
            $counts['mismatched']++;
        }
    }

    /** @param list<LocalPaymentSnapshot> $locals */
    private function recordDuplicateReference(
        ReconciliationRun $run,
        array $locals,
        ProviderPaymentSnapshot $provider,
    ): void {
        ReconciliationItem::query()->create([
            'reconciliation_run_id' => $run->id,
            'local_payment_id' => null,
            'provider_reference' => $provider->reference,
            'result' => ReconciliationStatus::DUPLICATE_REFERENCE->value,
            'provider_status' => $provider->status,
            'provider_amount_minor' => $provider->amountMinor,
            'provider_refunded_amount_minor' => $provider->refundedAmountMinor,
            'provider_currency' => strtoupper($provider->currency),
            'differences' => [
                'local_records' => [
                    'local' => count($locals),
                    'provider' => 1,
                ],
                'local_payment_ids' => array_map(fn (LocalPaymentSnapshot $local) => $local->id, $locals),
            ],
            'provider_snapshot' => $provider->raw,
        ]);
    }

    private function mergeProviderOnlySnapshots(
        ProviderPaymentSnapshot $existing,
        ProviderPaymentSnapshot $next,
    ): ProviderPaymentSnapshot {
        $existingRows = (array) ($existing->raw['capture_transaction_amounts'] ?? []);
        $nextRows = (array) ($next->raw['capture_transaction_amounts'] ?? []);

        // The PayPal Order reporting mode exposes each Capture row. Merge by Capture ID,
        // not by page, so duplicated or overlapping pages never double-count money.
        if ($existingRows !== [] && $nextRows !== []) {
            if ($existing->currency !== $next->currency) {
                throw new \UnexpectedValueException('One provider reference was reported with multiple currencies.');
            }

            $newRows = array_diff_key($nextRows, $existingRows);
            if ($newRows === []) {
                return $existing;
            }

            $allRows = $existingRows + $newRows;
            $raw = $existing->raw;
            $raw['capture_transaction_amounts'] = $allRows;
            $raw['capture_transaction_ids'] = array_keys($allRows);
            $statuses = array_map(
                fn (array $row): string => (string) ($row['status'] ?? 'unknown'),
                array_values($allRows),
            );
            $refundedAmounts = array_map(
                fn (array $row): mixed => $row['refunded_amount_minor'] ?? null,
                array_values($allRows),
            );
            $allRefundsKnown = ! in_array(null, $refundedAmounts, true);

            return new ProviderPaymentSnapshot(
                gateway: $existing->gateway,
                reference: $existing->reference,
                status: $this->mergeProviderStatuses($statuses),
                amountMinor: array_sum(array_map(
                    fn (array $row): int => (int) ($row['amount_minor'] ?? 0),
                    array_values($allRows),
                )),
                currency: $existing->currency,
                refundedAmountMinor: $allRefundsKnown ? array_sum($refundedAmounts) : null,
                createdAt: $existing->createdAt ?? $next->createdAt,
                raw: $raw,
            );
        }

        // Legacy/generic snapshots without per-Capture amounts cannot be safely partially merged.
        $existingIds = (array) ($existing->raw['capture_transaction_ids'] ?? []);
        $nextIds = (array) ($next->raw['capture_transaction_ids'] ?? []);
        $newIds = array_values(array_diff($nextIds, $existingIds));
        if ($newIds === []) {
            return $existing;
        }

        if ($existing->currency !== $next->currency) {
            throw new \UnexpectedValueException('One provider reference was reported with multiple currencies.');
        }

        // Without an amount for each ID, only add a page aggregate if every ID on that
        // page is new. Otherwise keeping the existing total is safer than double-counting.
        $canAddWholePage = count($newIds) === count($nextIds);
        $raw = $existing->raw;
        $raw['capture_transaction_ids'] = array_values(array_unique(array_merge($existingIds, $newIds)));

        return new ProviderPaymentSnapshot(
            gateway: $existing->gateway,
            reference: $existing->reference,
            status: $canAddWholePage
                ? $this->mergeProviderStatuses([$existing->status, $next->status])
                : 'unknown',
            amountMinor: $existing->amountMinor + ($canAddWholePage ? $next->amountMinor : 0),
            currency: $existing->currency,
            refundedAmountMinor: null,
            createdAt: $existing->createdAt ?? $next->createdAt,
            raw: $raw,
        );
    }

    /** @param list<string> $statuses */
    private function mergeProviderStatuses(array $statuses): string
    {
        if ($statuses === [] || in_array('unknown', $statuses, true)) {
            return 'unknown';
        }

        if (count(array_filter($statuses, fn (string $status) => $status === 'refunded')) === count($statuses)) {
            return 'refunded';
        }

        if (count(array_filter($statuses, fn (string $status) => $status === 'reversed')) === count($statuses)) {
            return 'reversed';
        }

        if (in_array('reversed', $statuses, true)) {
            return 'unknown';
        }

        if (in_array('pending', $statuses, true)) {
            return 'pending';
        }

        if (in_array('partially_refunded', $statuses, true)
            || (in_array('refunded', $statuses, true) && in_array('succeeded', $statuses, true))) {
            return 'partially_refunded';
        }

        if (count(array_filter($statuses, fn (string $status) => $status === 'succeeded')) === count($statuses)) {
            return 'succeeded';
        }

        if (count(array_filter($statuses, fn (string $status) => $status === 'failed')) === count($statuses)) {
            return 'failed';
        }

        return 'unknown';
    }

    private function recordProviderOnly(ReconciliationRun $run, ProviderPaymentSnapshot $provider): void
    {
        ReconciliationItem::query()->create([
            'reconciliation_run_id' => $run->id,
            'local_payment_id' => null,
            'provider_reference' => $provider->reference,
            'result' => ReconciliationStatus::LOCAL_NOT_FOUND->value,
            'provider_status' => $provider->status,
            'provider_amount_minor' => $provider->amountMinor,
            'provider_refunded_amount_minor' => $provider->refundedAmountMinor,
            'provider_currency' => strtoupper($provider->currency),
            'differences' => ['local_payment' => ['local' => null, 'provider' => 'exists']],
            'provider_snapshot' => $provider->raw,
        ]);
    }

    private function recordProviderScanError(ReconciliationRun $run, ProviderPaymentSnapshot $provider): void
    {
        ReconciliationItem::query()->create([
            'reconciliation_run_id' => $run->id,
            'local_payment_id' => null,
            'provider_reference' => $provider->reference,
            'result' => ReconciliationStatus::PROVIDER_ERROR->value,
            'provider_status' => $provider->status,
            'provider_currency' => strtoupper($provider->currency),
            'differences' => ['provider_error' => 'Provider period scan did not finish; this Order total may be incomplete.'],
            'provider_snapshot' => $provider->raw,
        ]);
    }

    private function recordLocalOnly(
        ReconciliationRun $run,
        LocalPaymentSnapshot $local,
        ReconciliationStatus $status,
        ?string $reason = null,
    ): void {
        ReconciliationItem::query()->create([
            'reconciliation_run_id' => $run->id,
            'local_payment_id' => $local->id,
            'provider_reference' => $local->providerReference,
            'result' => $status->value,
            'local_status' => $local->status,
            'local_amount_minor' => $local->amountMinor,
            'local_refunded_amount_minor' => $local->refundedAmountMinor,
            'local_currency' => strtoupper($local->currency),
            'differences' => $reason ? ['reason' => $reason] : ['provider_payment' => ['local' => 'exists', 'provider' => null]],
        ]);
    }

    private function recordLocalError(ReconciliationRun $run, LocalPaymentSnapshot $local, Throwable $exception): void
    {
        ReconciliationItem::query()->create([
            'reconciliation_run_id' => $run->id,
            'local_payment_id' => $local->id,
            'provider_reference' => $local->providerReference,
            'result' => ReconciliationStatus::PROVIDER_ERROR->value,
            'local_status' => $local->status,
            'local_amount_minor' => $local->amountMinor,
            'local_refunded_amount_minor' => $local->refundedAmountMinor,
            'local_currency' => strtoupper($local->currency),
            'differences' => ['provider_error' => $this->safeMessage($exception)],
        ]);
    }

    private function safeMessage(Throwable $exception): string
    {
        // Do not persist request headers, credentials, or complete provider response bodies.
        return mb_substr(class_basename($exception).': '.$exception->getMessage(), 0, 500);
    }
}
