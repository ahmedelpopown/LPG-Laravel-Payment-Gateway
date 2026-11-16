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

final class ConfirmStripePayment
{
    public function __construct(
        private readonly CaptureStripeCheckoutSession $captureStripeCheckoutSession,
    ) {}

    public function handle(
        BookingPayment $payment,
        Model $customer,
        string $stripeSessionId,
    ): BookingPayment {
        if (trim($stripeSessionId) === '') {
            throw new PaymentGatewayException(
                'Stripe did not return a Checkout Session reference.',
                'stripe_return_session_reference_missing',
            );
        }

        $verifiedPayment = DB::transaction(function () use ($payment, $customer, $stripeSessionId): BookingPayment {
            $currentPayment = BookingPayment::query()
                ->with('booking')
                ->whereKey($payment->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ((int) $currentPayment->booking->user_id !== (int) $customer->getKey()) {
                throw new AuthorizationException('You are not allowed to confirm this booking payment.');
            }

            if ($currentPayment->provider !== PaymentProvider::Stripe) {
                throw new PaymentGatewayException(
                    'This payment does not belong to Stripe.',
                    'payment_provider_mismatch',
                );
            }

            if (! is_string($currentPayment->provider_reference)
                || ! hash_equals($currentPayment->provider_reference, $stripeSessionId)) {
                throw new PaymentGatewayException(
                    'The Stripe Checkout Session returned by the browser does not match this booking payment.',
                    'stripe_return_session_mismatch',
                );
            }

            if (in_array($currentPayment->status, [
                PaymentStatus::Failed,
                PaymentStatus::Cancelled,
                PaymentStatus::Refunded,
                PaymentStatus::PartiallyRefunded,
            ], true)) {
                throw new PaymentGatewayException(
                    'This payment attempt is no longer eligible for browser confirmation.',
                    'stripe_payment_not_confirmable',
                );
            }

            return $currentPayment;
        });

        return $this->captureStripeCheckoutSession->handle($verifiedPayment, 'customer_return');
    }
}
