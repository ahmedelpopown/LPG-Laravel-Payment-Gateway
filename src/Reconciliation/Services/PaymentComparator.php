<?php

namespace Ahmed\LaravelPayment\Reconciliation\Services;

use Ahmed\LaravelPayment\Reconciliation\DTOs\ComparisonResult;
use Ahmed\LaravelPayment\Reconciliation\DTOs\LocalPaymentSnapshot;
use Ahmed\LaravelPayment\Reconciliation\DTOs\ProviderPaymentSnapshot;
use Ahmed\LaravelPayment\Reconciliation\Enums\ReconciliationStatus;

final class PaymentComparator
{
    public function compare(LocalPaymentSnapshot $local, ProviderPaymentSnapshot $provider): ComparisonResult
    {
        $differences = [];

        if (strtoupper($local->currency) !== strtoupper($provider->currency)) {
            $differences['currency'] = [
                'local' => strtoupper($local->currency),
                'provider' => strtoupper($provider->currency),
            ];
        }

        if ($local->amountMinor !== $provider->amountMinor) {
            $differences['amount_minor'] = [
                'local' => $local->amountMinor,
                'provider' => $provider->amountMinor,
            ];
        }

        // A null refund amount means that side cannot provide a complete figure.
        // Never manufacture a refund mismatch by comparing an unknown value to zero.
        if ($local->refundedAmountMinor !== null
            && $provider->refundedAmountMinor !== null
            && $local->refundedAmountMinor !== $provider->refundedAmountMinor) {
            $differences['refunded_amount_minor'] = [
                'local' => $local->refundedAmountMinor,
                'provider' => $provider->refundedAmountMinor,
            ];
        }

        $localStatusMap = (array) config('laravel-payment-reconciliation.local_status_map', []);
        $gatewayStatusMaps = (array) config('laravel-payment-reconciliation.local_status_map_by_gateway', []);
        $localStatusMap = array_merge(
            $localStatusMap,
            (array) ($gatewayStatusMaps[$local->gateway] ?? []),
        );
        $localStatus = $localStatusMap[strtolower(trim($local->status))] ?? $local->status;

        if ($this->normalizeStatus((string) $localStatus) !== $this->normalizeStatus($provider->status)) {
            $differences['status'] = [
                'local' => $local->status,
                'provider' => $provider->status,
            ];
        }

        if ($differences === []) {
            return new ComparisonResult(ReconciliationStatus::MATCHED);
        }

        $primaryStatus = match (true) {
            array_key_exists('currency', $differences) => ReconciliationStatus::CURRENCY_MISMATCH,
            array_key_exists('amount_minor', $differences) => ReconciliationStatus::AMOUNT_MISMATCH,
            array_key_exists('refunded_amount_minor', $differences) => ReconciliationStatus::REFUND_MISMATCH,
            default => ReconciliationStatus::STATUS_MISMATCH,
        };

        return new ComparisonResult($primaryStatus, $differences);
    }

    private function normalizeStatus(string $status): string
    {
        return strtolower(trim($status));
    }
}
