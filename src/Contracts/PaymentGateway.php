<?php

declare(strict_types=1);

namespace Ahmed\LaravelPayment\Contracts;

use Ahmed\LaravelPayment\Data\CheckoutSessionData;
use Ahmed\LaravelPayment\Data\PaymentResultData;
use Ahmed\LaravelPayment\Data\RefundResultData;
use Illuminate\Database\Eloquent\Model;

/**
 * Defines the operations every payment provider must support.
 *
 * Provider-specific HTTP requests and response parsing belong in the gateway
 * implementation and its client, not in controllers or booking services.
 */
interface PaymentGateway
{
    /** Determine whether the provider can process the requested currency. */
    public function supportsCurrency(string $currency): bool;

    /**
     * Create a provider checkout session for the persisted Eloquent payment record.
     */
    public function createCheckout(Model $payment): CheckoutSessionData;

    /**
     * Capture/confirm the payment after the customer approves the checkout.
     */
    public function capture(Model $payment): PaymentResultData;

    /**
     * Refund all or part of the captured payment.
     *
     * The amount must be a decimal string, for example "25.00".
     */
    public function refund(Model $payment, string $amount): RefundResultData;
}
