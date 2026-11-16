<?php

declare(strict_types=1);

namespace Ahmed\LaravelPayment\Core\Actions;

use Ahmed\LaravelPayment\Core\Models\Payment;
use Ahmed\LaravelPayment\Core\Models\PaymentRefund;
use Ahmed\LaravelPayment\Contracts\IdempotentRefundGateway;
use Ahmed\LaravelPayment\Data\RefundResultData;
use Ahmed\LaravelPayment\Enums\PaymentStatus;
use Ahmed\LaravelPayment\Enums\RefundStatus;
use Ahmed\LaravelPayment\Exceptions\PaymentGatewayException;
use Ahmed\LaravelPayment\Payments\PaymentGatewayManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

final class RefundPayment
{
    public function __construct(private readonly PaymentGatewayManager $gateways) {}

    public function handle(
        Payment $payment,
        string $amount,
        string $idempotencyKey,
        ?Model $requestedBy = null,
        ?string $reason = null,
    ): PaymentRefund {
        $amount = $this->normalizeAmount($amount);
        $idempotencyKey = trim($idempotencyKey);

        if ($idempotencyKey === '' || strlen($idempotencyKey) > 100) {
            throw new InvalidArgumentException('A refund idempotency key of 1 to 100 characters is required.');
        }

        if ($requestedBy !== null && ! $requestedBy->exists) {
            throw new InvalidArgumentException('The refund requester must be a persisted Eloquent model.');
        }

        $refund = DB::transaction(function () use ($payment, $amount, $idempotencyKey, $requestedBy, $reason): PaymentRefund {
            $lockedPayment = Payment::query()->whereKey($payment->getKey())->lockForUpdate()->firstOrFail();
            $existing = PaymentRefund::query()->where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();

            if ($existing) {
                if ((string) $existing->payment_id !== (string) $lockedPayment->getKey()
                    || $this->normalizeAmount((string) $existing->amount) !== $amount) {
                    throw new PaymentGatewayException('Refund idempotency key was reused for a different request.', 'refund_idempotency_conflict');
                }

                if ($existing->status === RefundStatus::Completed) {
                    return $existing;
                }

                $existing->forceFill(['status' => RefundStatus::Processing, 'failure_message' => null])->save();

                return $existing->fresh();
            }

            if (! in_array($lockedPayment->status, [PaymentStatus::Paid, PaymentStatus::PartiallyRefunded], true)) {
                throw new PaymentGatewayException('Only captured payments can be refunded.', 'payment_not_refundable');
            }

            $amountMinor = $this->toMinor($amount);
            $reservedMinor = PaymentRefund::query()
                ->where('payment_id', $lockedPayment->getKey())
                ->whereIn('status', [RefundStatus::Pending->value, RefundStatus::Processing->value, RefundStatus::Completed->value])
                ->get(['amount'])
                ->sum(fn (PaymentRefund $row): int => $this->toMinor((string) $row->amount));

            $remainingMinor = max(0, $this->toMinor((string) $lockedPayment->amount) - $reservedMinor);

            if ($amountMinor > $remainingMinor) {
                throw new PaymentGatewayException('Refund amount exceeds the remaining refundable balance.', 'refund_amount_exceeds_balance');
            }

            $refund = PaymentRefund::query()->create([
                'payment_id' => $lockedPayment->getKey(),
                'provider' => $lockedPayment->provider->value,
                'idempotency_key' => $idempotencyKey,
                'amount' => $amount,
                'currency' => strtoupper((string) $lockedPayment->currency),
                'status' => RefundStatus::Processing,
                'reason' => $reason,
                'requested_by_type' => $requestedBy?->getMorphClass(),
                'requested_by_id' => $requestedBy ? (string) $requestedBy->getKey() : null,
            ]);

            return $refund;
        });

        if ($refund->status === RefundStatus::Completed) {
            return $refund;
        }

        try {
            $gateway = $this->gateways->driver($payment->provider);
            $freshPayment = $payment->fresh();
            $result = $gateway instanceof IdempotentRefundGateway
                ? $gateway->refundWithIdempotency($freshPayment, (string) $refund->amount, (string) $refund->idempotency_key)
                : $gateway->refund($freshPayment, (string) $refund->amount);
        } catch (PaymentGatewayException $exception) {
            $providerCode = (string) $exception->providerCode;
            $definitelyNotSubmitted = in_array($providerCode, [
                'stripe_secret_key_missing',
                'paypal_credentials_missing',
                'paypal_mode_invalid',
                'payment_not_refundable',
                'payment_provider_mismatch',
                'payment_not_persisted',
                'stripe_payment_intent_reference_missing',
                'paypal_capture_reference_missing',
            ], true);
            $ambiguous = ! $definitelyNotSubmitted && (
                $exception->httpStatus === null
                || in_array($exception->httpStatus, [408, 429], true)
                || ($exception->httpStatus !== null && $exception->httpStatus >= 500)
                || str_contains($providerCode, 'connection')
            );

            $refund->forceFill([
                // A timeout/server error may happen after the provider accepted the refund.
                // Keep the balance reserved and retry with the same provider idempotency key.
                'status' => $ambiguous ? RefundStatus::Processing : RefundStatus::Failed,
                'failure_message' => $exception->getMessage(),
            ])->save();

            throw $exception;
        } catch (Throwable $exception) {
            // Keep the reservation: the provider may have accepted the refund before a network failure.
            $refund->forceFill([
                'status' => RefundStatus::Processing,
                'failure_message' => $exception->getMessage(),
            ])->save();

            throw $exception;
        }

        if ($this->normalizeAmount($result->amount) !== $this->normalizeAmount((string) $refund->amount)
            || strtoupper($result->currency) !== strtoupper((string) $refund->currency)) {
            $refund->forceFill([
                'status' => RefundStatus::Processing,
                'failure_message' => 'Provider refund amount or currency did not match the request.',
            ])->save();

            throw new PaymentGatewayException('Provider refund amount/currency mismatch. Refund remains reserved for reconciliation.', 'refund_result_mismatch');
        }

        $completed = $result->status !== PaymentStatus::Processing;

        $refund->forceFill([
            'provider_refund_id' => $result->providerRefundId,
            'status' => $completed ? RefundStatus::Completed : RefundStatus::Processing,
            'metadata' => array_merge($refund->metadata ?? [], $result->metadata),
            'failure_message' => null,
            'processed_at' => $completed ? now() : null,
        ])->save();

        if ($completed) {
            DB::transaction(function () use ($payment): void {
                $lockedPayment = Payment::query()->whereKey($payment->getKey())->lockForUpdate()->firstOrFail();
                $completedMinor = PaymentRefund::query()
                    ->where('payment_id', $lockedPayment->getKey())
                    ->where('status', RefundStatus::Completed->value)
                    ->get(['amount'])
                    ->sum(fn (PaymentRefund $row): int => $this->toMinor((string) $row->amount));
                $totalMinor = $this->toMinor((string) $lockedPayment->amount);
                $refundedMinor = min($completedMinor, $totalMinor);
                $full = $refundedMinor >= $totalMinor;

                $lockedPayment->forceFill([
                    'refunded_amount' => $this->fromMinor($refundedMinor),
                    'status' => $full ? PaymentStatus::Refunded : PaymentStatus::PartiallyRefunded,
                    'refunded_at' => $full ? now() : null,
                ])->save();
            });
        }

        return $refund->fresh();
    }

    private function normalizeAmount(string $amount): string
    {
        if (! preg_match('/^(\d{1,14})(?:\.(\d{1,2}))?$/D', trim($amount), $matches)) {
            throw new InvalidArgumentException('Refund amount must be a positive decimal with up to two decimal places.');
        }

        $minor = ((int) $matches[1] * 100) + (int) str_pad($matches[2] ?? '', 2, '0');
        if ($minor <= 0) {
            throw new InvalidArgumentException('Refund amount must be greater than zero.');
        }

        return $this->fromMinor($minor);
    }

    private function toMinor(string $amount): int
    {
        $normalized = $this->normalizeAmount($amount);
        [$whole, $fraction] = array_pad(explode('.', $normalized, 2), 2, '00');
        return ((int) $whole * 100) + (int) $fraction;
    }

    private function fromMinor(int $minor): string
    {
        return intdiv($minor, 100).'.'.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }
}
