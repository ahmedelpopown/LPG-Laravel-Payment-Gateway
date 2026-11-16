<?php

declare(strict_types=1);

namespace Ahmed\LaravelPayment\Actions\Payments;

use Ahmed\LaravelPayment\Enums\PaymentProvider;
use Ahmed\LaravelPayment\Enums\PaymentStatus;
use Ahmed\LaravelPayment\Exceptions\PaymentGatewayException;
use Ahmed\LaravelPayment\Models\BookingPayment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class CancelBookingPayment
{
    public function handle(
        BookingPayment $payment,
        Model $customer,
        string $paypalOrderId,
    ): BookingPayment {
        return DB::transaction(function () use ($payment, $customer, $paypalOrderId): BookingPayment {
            $currentPayment = BookingPayment::query()
                ->with('booking')
                ->whereKey($payment->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ((int) $currentPayment->booking->user_id !== (int) $customer->getKey()) {
                throw new AuthorizationException('You are not allowed to cancel this booking payment.');
            }

            if ($currentPayment->provider !== PaymentProvider::PayPal
                || ! is_string($currentPayment->provider_reference)
                || ! hash_equals($currentPayment->provider_reference, $paypalOrderId)) {
                throw new PaymentGatewayException(
                    'The PayPal cancellation reference does not match this booking payment.',
                    'paypal_cancel_order_mismatch',
                );
            }

            if ($currentPayment->status !== PaymentStatus::Pending) {
                return $currentPayment;
            }

            $currentPayment->forceFill([
                'status' => PaymentStatus::Cancelled,
                'metadata' => array_merge($currentPayment->metadata ?? [], [
                    'cancelled_by_customer_at' => now()->toIso8601String(),
                ]),
            ])->save();

            return $currentPayment->fresh();
        });
    }
}
