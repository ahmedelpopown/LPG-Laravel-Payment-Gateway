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
 * Confirms a Stripe Checkout Session after the browser return or webhook.
 * Stripe Checkout performs the card capture automatically; the gateway retrieves
 * the Session from Stripe and verifies its amount, currency and metadata here.
 */
final class CaptureStripeCheckoutSession
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

            if ($currentPayment->provider !== PaymentProvider::Stripe) {
                throw new PaymentGatewayException(
                    'This payment does not belong to Stripe.',
                    'payment_provider_mismatch',
                );
            }

            if (! is_string($currentPayment->provider_reference)
                || trim($currentPayment->provider_reference) === '') {
                throw new PaymentGatewayException(
                    'The payment has no Stripe Checkout Session reference.',
                    'stripe_session_reference_missing',
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
                    'confirmation_started_at' => now()->toIso8601String(),
                    'confirmation_source' => $source,
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

        $result = $this->gatewayManager
            ->driver(PaymentProvider::Stripe)
            ->capture($lockedPayment);

        return $this->finalizeBookingPayment->handle($lockedPayment, $result);
    }
}
