<?php

declare(strict_types=1);

namespace Ahmed\LaravelPayment\Actions\Payments;

use Ahmed\LaravelPayment\Enums\PaymentProvider;
use Ahmed\LaravelPayment\Enums\PaymentStatus;
use Ahmed\LaravelPayment\Exceptions\PaymentGatewayException;
use Ahmed\LaravelPayment\Models\BookingPayment;
use Ahmed\LaravelPayment\Payments\PaymentGatewayManager;
use Illuminate\Support\Facades\DB;

/**
 * Runs the server-side capture operation after PayPal has approved an order.
 * This action is shared by the browser return flow and the PayPal webhook flow.
 */
final class CapturePayPalOrder
{
    public function __construct(
        private readonly PaymentGatewayManager $gatewayManager,
        private readonly FinalizeBookingPayment $finalizeBookingPayment,
    ) {}

    public function handle(BookingPayment $payment, string $source): BookingPayment
    {
        $lockedPayment = DB::transaction(function () use ($payment, $source): BookingPayment {
            $currentPayment = BookingPayment::query()
                ->whereKey($payment->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($currentPayment->provider !== PaymentProvider::PayPal) {
                throw new PaymentGatewayException(
                    'This payment does not belong to PayPal.',
                    'payment_provider_mismatch',
                );
            }

            if (! is_string($currentPayment->provider_reference)
                || trim($currentPayment->provider_reference) === '') {
                throw new PaymentGatewayException(
                    'The payment has no PayPal order reference.',
                    'paypal_order_reference_missing',
                );
            }

            if (in_array($currentPayment->status, [
                PaymentStatus::Paid,
                PaymentStatus::Refunded,
                PaymentStatus::PartiallyRefunded,
            ], true)) {
                return $currentPayment;
            }

            $currentPayment->forceFill([
                'status' => PaymentStatus::Processing,
                'metadata' => array_merge($currentPayment->metadata ?? [], [
                    'capture_started_at' => now()->toIso8601String(),
                    'capture_source' => $source,
                ]),
            ])->save();

            return $currentPayment->fresh();
        });

        if (in_array($lockedPayment->status, [
            PaymentStatus::Paid,
            PaymentStatus::Refunded,
            PaymentStatus::PartiallyRefunded,
        ], true)) {
            return $lockedPayment;
        }

        // The provider request uses a stable PayPal-Request-Id derived from the
        // payment idempotency key, so browser and webhook captures can safely race.
        $result = $this->gatewayManager
            ->driver(PaymentProvider::PayPal)
            ->capture($lockedPayment);

        return $this->finalizeBookingPayment->handle($lockedPayment, $result);
    }
}
