<?php

declare(strict_types=1);

namespace Ahmed\LaravelPayment\Actions\Payments;

use Ahmed\LaravelPayment\Contracts\IdempotentRefundGateway;
use Ahmed\LaravelPayment\Enums\PaymentStatus;
use Ahmed\LaravelPayment\Enums\RefundStatus;
use Ahmed\LaravelPayment\Exceptions\PaymentGatewayException;
use Ahmed\LaravelPayment\Models\BookingPayment;
use Ahmed\LaravelPayment\Models\BookingPaymentRefund;
use Illuminate\Database\Eloquent\Model;
use Ahmed\LaravelPayment\Payments\PaymentGatewayManager;
use Ahmed\LaravelPayment\Services\RefundBalanceCalculator;
use Illuminate\Support\Facades\DB;
use Throwable;

final class RefundBookingPayment
{
    public function __construct(
        private readonly PaymentGatewayManager $gateways,
        private readonly RefundBalanceCalculator $balance,
    ) {}

    public function handle(
        BookingPayment $payment,
        string $amount,
        string $idempotencyKey,
        Model $requestedBy,
        ?string $reason = null,
    ): BookingPaymentRefund {
        $amountMinor = $this->balance->toMinorUnits($amount);

        if ($amountMinor <= 0) {
            throw new PaymentGatewayException('يجب أن يكون مبلغ الاسترداد أكبر من صفر.', 'refund_amount_invalid');
        }

        if (trim($idempotencyKey) === '') {
            throw new PaymentGatewayException('مفتاح منع التكرار مطلوب.', 'refund_idempotency_key_missing');
        }

        // Reserve the requested amount before calling the provider. The DB lock prevents
        // two admin requests from reserving the same refundable balance concurrently.
        $refund = DB::transaction(function () use ($payment, $amount, $amountMinor, $idempotencyKey, $requestedBy, $reason): BookingPaymentRefund {
            $lockedPayment = BookingPayment::query()
                ->whereKey($payment->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $existing = BookingPaymentRefund::query()
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing?->status === RefundStatus::Completed) {
                return $existing;
            }

            if (! in_array($lockedPayment->status, [PaymentStatus::Paid, PaymentStatus::PartiallyRefunded], true)) {
                throw new PaymentGatewayException('لا يمكن استرداد عملية دفع غير مكتملة التحصيل.', 'payment_not_refundable');
            }

            $remaining = $this->balance->remainingAmount($lockedPayment, $existing?->getKey());
            if ($amountMinor > $this->balance->toMinorUnits($remaining)) {
                throw new PaymentGatewayException('مبلغ الاسترداد أكبر من الرصيد المتاح للاسترداد.', 'refund_amount_exceeds_balance');
            }

            if ($existing !== null) {
                if ((int) $existing->booking_payment_id !== (int) $lockedPayment->getKey()
                    || $this->balance->toMinorUnits((string) $existing->amount) !== $amountMinor) {
                    throw new PaymentGatewayException('مفتاح منع التكرار مستخدم لطلب استرداد مختلف.', 'refund_idempotency_conflict');
                }

                $existing->forceFill([
                    'status' => RefundStatus::Processing,
                    'failure_message' => null,
                ])->save();

                return $existing->fresh();
            }

            return BookingPaymentRefund::query()->create([
                'booking_payment_id' => $lockedPayment->getKey(),
                'provider' => $lockedPayment->provider->value,
                'idempotency_key' => $idempotencyKey,
                'amount' => $this->balance->formatMinorUnits($amountMinor),
                'currency' => strtoupper((string) $lockedPayment->currency),
                'status' => RefundStatus::Processing,
                'reason' => $reason,
                'requested_by_employee_id' => $requestedBy->getKey(),
            ]);
        });

        if ($refund->status === RefundStatus::Completed) {
            return $refund;
        }

        try {
            $gateway = $this->gateways->driver($payment->provider);
            $freshPayment = $payment->fresh();
            $result = $gateway instanceof IdempotentRefundGateway
                ? $gateway->refundWithIdempotency(
                    $freshPayment,
                    (string) $refund->amount,
                    (string) $refund->idempotency_key,
                )
                : $gateway->refund($freshPayment, (string) $refund->amount);
        } catch (PaymentGatewayException $exception) {
            $providerCode = (string) $exception->providerCode;
            $definitelyNotSubmitted = in_array($providerCode, [
                'stripe_secret_key_missing',
                'paypal_credentials_missing',
                'paypal_mode_invalid',
                'payment_not_refundable',
                'payment_provider_mismatch',
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
                'status' => $ambiguous ? RefundStatus::Processing : RefundStatus::Failed,
                'failure_message' => $exception->getMessage(),
            ])->save();

            throw $exception;
        } catch (Throwable $exception) {
            // A transport/runtime error may happen after the provider accepted the refund.
            // Keep the amount reserved until the provider result can be reconciled.
            $refund->forceFill([
                'status' => RefundStatus::Processing,
                'failure_message' => $exception->getMessage(),
            ])->save();

            throw $exception;
        }

        if ($this->balance->toMinorUnits($result->amount) !== $this->balance->toMinorUnits((string) $refund->amount)
            || strtoupper($result->currency) !== strtoupper((string) $refund->currency)) {
            $refund->forceFill([
                'status' => RefundStatus::Processing,
                'failure_message' => 'Provider refund amount/currency did not match the requested refund.',
            ])->save();

            throw new PaymentGatewayException('بيانات الاسترداد التي أعادها مزود الدفع لا تطابق الطلب. تُرك الطلب قيد المراجعة لتجنب تكرار الاسترداد.', 'refund_result_mismatch');
        }

        try {
            $refund->forceFill([
                'provider_refund_id' => $result->providerRefundId,
                'status' => $result->status === PaymentStatus::Processing ? RefundStatus::Processing : RefundStatus::Completed,
                'metadata' => array_merge($refund->metadata ?? [], $result->metadata),
                'failure_message' => null,
                'processed_at' => $result->status === PaymentStatus::Processing ? null : now(),
            ])->save();

            return DB::transaction(function () use ($refund): BookingPaymentRefund {
                $lockedRefund = BookingPaymentRefund::query()
                    ->whereKey($refund->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($lockedRefund->status === RefundStatus::Completed) {
                    $payment = BookingPayment::query()
                        ->whereKey($lockedRefund->booking_payment_id)
                        ->lockForUpdate()
                        ->firstOrFail();

                    $completedTotal = (int) BookingPaymentRefund::query()
                        ->where('booking_payment_id', $payment->getKey())
                        ->where('status', RefundStatus::Completed->value)
                        ->get(['amount'])
                        ->sum(fn (BookingPaymentRefund $item): int => $this->balance->toMinorUnits((string) $item->amount));

                    $fullRefund = $completedTotal >= $this->balance->toMinorUnits((string) $payment->amount);
                    $payment->forceFill([
                        'status' => $fullRefund ? PaymentStatus::Refunded : PaymentStatus::PartiallyRefunded,
                        'refunded_at' => $fullRefund ? now() : null,
                    ])->save();
                }

                return $lockedRefund->fresh();
            });
        } catch (Throwable $exception) {
            // The provider may already have accepted the refund. Preserve the reservation.
            $refund->forceFill([
                'status' => RefundStatus::Processing,
                'failure_message' => $exception->getMessage(),
            ])->save();

            throw $exception;
        }
    }
}
