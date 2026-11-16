<?php

declare(strict_types=1);

namespace Ahmed\LaravelPayment\Actions\Payments;

use Ahmed\LaravelPayment\Data\PaymentResultData;
use Ahmed\LaravelPayment\Enums\PaymentStatus;
use Ahmed\LaravelPayment\Exceptions\PaymentGatewayException;
use Ahmed\LaravelPayment\Models\BookingPayment;
use Illuminate\Support\Facades\DB;

final class FinalizeBookingPayment
{
    public function handle(
        BookingPayment $payment,
        PaymentResultData $result,
    ): BookingPayment {
        if ($this->normalizeAmount($result->amount) !== $this->normalizeAmount((string) $payment->amount)
            || strtoupper($result->currency) !== strtoupper((string) $payment->currency)) {
            throw new PaymentGatewayException(
                'The provider payment result does not match the stored booking amount and currency.',
                'booking_payment_result_mismatch',
            );
        }

        if ($result->providerReference !== null
            && $payment->provider_reference !== null
            && ! hash_equals((string) $payment->provider_reference, $result->providerReference)) {
            throw new PaymentGatewayException(
                'The provider returned a different order reference for this payment.',
                'booking_payment_reference_mismatch',
            );
        }

        if (! in_array($result->status, [
            PaymentStatus::Paid,
            PaymentStatus::Processing,
            PaymentStatus::Failed,
        ], true)) {
            throw new PaymentGatewayException(
                'The provider returned an unsupported payment state.',
                'booking_payment_result_status_invalid',
            );
        }

        if ($result->status === PaymentStatus::Paid
            && (! is_string($result->transactionId) || trim($result->transactionId) === '')) {
            throw new PaymentGatewayException(
                'The provider confirmed payment without returning a transaction reference.',
                'booking_payment_transaction_reference_missing',
            );
        }

        return DB::transaction(function () use ($payment, $result): BookingPayment {
            $lockedPayment = BookingPayment::query()
                ->whereKey($payment->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (in_array($lockedPayment->status, [
                PaymentStatus::Refunded,
                PaymentStatus::PartiallyRefunded,
            ], true)) {
                return $lockedPayment;
            }

            if ($lockedPayment->status === PaymentStatus::Paid) {
                if ($result->status !== PaymentStatus::Paid) {
                    return $lockedPayment;
                }

                if ($lockedPayment->provider_transaction_id !== null
                    && $result->transactionId !== $lockedPayment->provider_transaction_id) {
                    throw new PaymentGatewayException(
                        'A different provider transaction was returned for an already paid booking payment.',
                        'booking_payment_duplicate_transaction_mismatch',
                    );
                }

                return $lockedPayment;
            }

            if (in_array($lockedPayment->status, [PaymentStatus::Cancelled, PaymentStatus::Failed], true)
                && $result->status !== PaymentStatus::Paid) {
                return $lockedPayment;
            }

            $attributes = [
                'status' => $result->status,
                'provider_reference' => $result->providerReference ?? $lockedPayment->provider_reference,
                'provider_transaction_id' => $result->transactionId ?? $lockedPayment->provider_transaction_id,
                'metadata' => array_merge($lockedPayment->metadata ?? [], $result->metadata, [
                    'last_provider_confirmation_at' => now()->toIso8601String(),
                ]),
            ];

            if ($result->status === PaymentStatus::Paid) {
                $attributes['paid_at'] = $lockedPayment->paid_at ?? now();
            }

            $lockedPayment->forceFill($attributes)->save();

            return $lockedPayment->fresh();
        });
    }

    private function normalizeAmount(string $amount): string
    {
        if (! preg_match('/^(\d{1,10})(?:\.(\d{1,2}))?$/D', trim($amount), $matches)) {
            throw new PaymentGatewayException(
                'A payment amount contains an invalid numeric format.',
                'booking_payment_amount_invalid',
            );
        }

        return ((int) $matches[1]).'.'.str_pad($matches[2] ?? '', 2, '0');
    }
}
