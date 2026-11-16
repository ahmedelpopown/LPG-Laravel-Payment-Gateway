<?php

declare(strict_types=1);

namespace Ahmed\LaravelPayment\Payments\PayPal;

use Ahmed\LaravelPayment\Data\PayPalCredentialsData;
use Ahmed\LaravelPayment\Exceptions\PaymentGatewayException;
use Ahmed\LaravelPayment\Models\TenantPaymentCredential;

final class PayPalCredentialsResolver
{
    public function resolve(): PayPalCredentialsData
    {
        $tenancy = function_exists('tenancy') ? tenancy() : null;
        $tenant = $tenancy?->initialized ? $tenancy->tenant : null;

        if ($tenant !== null) {
            $credentials = TenantPaymentCredential::query()->find((string) $tenant->getTenantKey());
            $tenantId = (string) $tenant->getTenantKey();
            $clientId = $credentials?->paypal_client_id;
            $clientSecret = $credentials?->paypal_client_secret;
            $mode = is_string($credentials?->paypal_mode) ? strtolower(trim($credentials->paypal_mode)) : 'sandbox';
            $webhookId = $credentials?->paypal_webhook_id;
        } else {
            $tenantId = 'global';
            $clientId = config('payments.paypal.client_id');
            $clientSecret = config('payments.paypal.client_secret');
            $mode = (string) config('payments.paypal.mode', 'sandbox');
            $webhookId = config('payments.paypal.webhook_id');
        }

        if (! is_string($clientId) || trim($clientId) === ''
            || ! is_string($clientSecret) || trim($clientSecret) === '') {
            throw new PaymentGatewayException(
                'PayPal is not configured. Add its REST API credentials first.',
                'paypal_credentials_missing',
            );
        }

        if (! in_array($mode, ['sandbox', 'live'], true)) {
            throw new PaymentGatewayException(
                'The PayPal mode is invalid.',
                'paypal_mode_invalid',
            );
        }

        return new PayPalCredentialsData(
            tenantId: $tenantId,
            clientId: trim($clientId),
            clientSecret: trim($clientSecret),
            mode: $mode,
            webhookId: is_string($webhookId) ? $webhookId : null,
        );
    }
}
