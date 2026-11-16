<?php

declare(strict_types=1);

namespace Ahmed\LaravelPayment\Payments\Stripe;

use Ahmed\LaravelPayment\Data\StripeCredentialsData;
use Ahmed\LaravelPayment\Exceptions\PaymentGatewayException;
use Ahmed\LaravelPayment\Models\TenantPaymentCredential;

final class StripeCredentialsResolver
{
    public function resolve(bool $requireWebhookSecret = false): StripeCredentialsData
    {
        $tenancy = function_exists('tenancy') ? tenancy() : null;
        $tenant = $tenancy?->initialized ? $tenancy->tenant : null;

        if ($tenant !== null) {
            $credentials = TenantPaymentCredential::query()->find((string) $tenant->getTenantKey());
            $tenantId = (string) $tenant->getTenantKey();
            $secretKey = $credentials?->stripe_secret_key;
            $webhookSecret = $credentials?->stripe_webhook_secret;
        } else {
            $tenantId = 'global';
            $secretKey = config('payments.stripe.secret_key');
            $webhookSecret = config('payments.stripe.webhook_secret');
        }

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
            tenantId: $tenantId,
            secretKey: trim($secretKey),
            webhookSecret: is_string($webhookSecret) && trim($webhookSecret) !== '' ? trim($webhookSecret) : null,
        );
    }
}
