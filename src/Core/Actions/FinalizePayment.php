<?php

declare(strict_types=1);

namespace Ahmed\LaravelPayment\Core\Actions;

use Ahmed\LaravelPayment\Core\Models\Payment;
use Ahmed\LaravelPayment\Data\PaymentResultData;
use Ahmed\LaravelPayment\Enums\PaymentStatus;
use Ahmed\LaravelPayment\Exceptions\PaymentGatewayException;
use Illuminate\Support\Facades\DB;

final class FinalizePayment
{
    public function handle(Payment $payment, PaymentResultData $result): Payment
    {
        if ($this->normalizeAmount($result->amount) !== $this->normalizeAmount((string) $payment->amount)
            || strtoupper($result->currency) !== strtoupper((string) $payment->currency)) {
            throw new PaymentGatewayException('Provider result amount/currency does not match the saved payment.', 'payment_result_mismatch');
        }

        if ($result->providerReference !== null && $payment->provider_reference !== null
            && ! hash_equals((string) $payment->provider_reference, $result->providerReference)) {
            throw new PaymentGatewayException('Provider reference does not match the saved payment.', 'payment_reference_mismatch');
        }

        if (! in_array($result->status, [PaymentStatus::Paid, PaymentStatus::Processing, PaymentStatus::Failed], true)) {
            throw new PaymentGatewayException('Provider returned an unsupported payment state.', 'payment_result_status_invalid');
        }

        if ($result->status === PaymentStatus::Paid && (! $result->transactionId || trim($result->transactionId) === '')) {
            throw new PaymentGatewayException('Paid result must include a provider transaction reference.', 'payment_transaction_reference_missing');
        }

        return DB::transaction(function () use ($payment, $result): Payment {
            $locked = Payment::query()->whereKey($payment->getKey())->lockForUpdate()->firstOrFail();

            if (in_array($locked->status, [PaymentStatus::Refunded, PaymentStatus::PartiallyRefunded], true)) {
                return $locked;
            }

            if ($locked->status === PaymentStatus::Paid) {
                if ($result->status !== PaymentStatus::Paid) {
                    return $locked;
                }

                if ($locked->provider_transaction_id !== null
                    && $result->transactionId !== $locked->provider_transaction_id) {
                    throw new PaymentGatewayException('A different provider transaction was returned for an already-paid payment.', 'payment_duplicate_transaction_mismatch');
                }

                return $locked;
            }

            if (in_array($locked->status, [PaymentStatus::Cancelled, PaymentStatus::Failed], true)
                && $result->status !== PaymentStatus::Paid) {
                return $locked;
            }

            $attributes = [
                'status' => $result->status,
                'provider_reference' => $result->providerReference ?? $locked->provider_reference,
                'provider_transaction_id' => $result->transactionId ?? $locked->provider_transaction_id,
                'metadata' => array_merge($locked->metadata ?? [], $result->metadata, [
                    'last_provider_confirmation_at' => now()->toIso8601String(),
                ]),
            ];

            if ($result->status === PaymentStatus::Paid) {
                $attributes['paid_at'] = $locked->paid_at ?? now();
            }

            $locked->forceFill($attributes)->save();

            return $locked->fresh();
        });
    }

    private function normalizeAmount(string $amount): string
    {
        if (! preg_match('/^(\d{1,14})(?:\.(\d{1,2}))?$/D', trim($amount), $matches)) {
            throw new PaymentGatewayException('Invalid payment amount format.', 'payment_amount_invalid');
        }

        return ((int) $matches[1]).'.'.str_pad($matches[2] ?? '', 2, '0');
    }
}
