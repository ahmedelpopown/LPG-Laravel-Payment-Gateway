<?php

declare(strict_types=1);

namespace Ahmed\LaravelPayment\Payments\Stripe;

use Ahmed\LaravelPayment\Data\StripeCredentialsData;
use Ahmed\LaravelPayment\Exceptions\PaymentGatewayException;

final class StripeCredentialsResolver
{
    public function resolve(bool $requireWebhookSecret = false): StripeCredentialsData
    {
        $secretKey = config('payments.stripe.secret_key');
        $webhookSecret = config('payments.stripe.webhook_secret');

        if (! is_string($secretKey) || trim($secretKey) === '') {
            throw new PaymentGatewayException(
                'Stripe is not configured. Add its secret key first.',
                'stripe_secret_key_missing',
            );
        }

        if ($requireWebhookSecret && (! is_string($webhookSecret) || trim($webhookSecret) === '')) {
            throw new PaymentGatewayException(
                'The Stripe webhook signing secret is not configured.',
                'stripe_webhook_secret_missing',
            );
        }

        return new StripeCredentialsData(
            secretKey: trim($secretKey),
            webhookSecret: is_string($webhookSecret) && trim($webhookSecret) !== '' ? trim($webhookSecret) : null,
        );
    }
}
