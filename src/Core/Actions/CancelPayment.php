<?php

declare(strict_types=1);

namespace Ahmed\LaravelPayment\Core\Actions;

use Ahmed\LaravelPayment\Contracts\CancellableCheckoutGateway;
use Ahmed\LaravelPayment\Core\Models\Payment;
use Ahmed\LaravelPayment\Enums\PaymentProvider;
use Ahmed\LaravelPayment\Enums\PaymentStatus;
use Ahmed\LaravelPayment\Exceptions\PaymentGatewayException;
use Ahmed\LaravelPayment\Payments\PaymentGatewayManager;

/** Cancel a provider checkout only after the provider confirms it is unpaid and closed. */
final class CancelPayment
{
    public function __construct(
        private readonly PaymentGatewayManager $gateways,
        private readonly FinalizePayment $finalizer,
    ) {}

    public function handle(Payment $payment): Payment
    {
        $payment = $payment->fresh() ?? $payment;

        if (in_array($payment->status, [
            PaymentStatus::Paid,
            PaymentStatus::PartiallyRefunded,
            PaymentStatus::Refunded,
            PaymentStatus::Cancelled,
            PaymentStatus::Failed,
        ], true)) {
            return $payment;
        }

        if ($payment->provider !== PaymentProvider::Stripe) {
            throw new PaymentGatewayException(
                'Remote cancellation is not supported for this payment provider. The payment state was not changed.',
                'payment_provider_cancellation_unsupported',
            );
        }

        $gateway = $this->gateways->driver(PaymentProvider::Stripe);
        if (! $gateway instanceof CancellableCheckoutGateway) {
            throw new PaymentGatewayException(
                'The configured Stripe gateway does not support safe Checkout cancellation.',
                'stripe_checkout_cancellation_unsupported',
            );
        }

        $result = $gateway->cancelCheckout($payment);

        return $this->finalizer->handle($payment, $result);
    }
}
