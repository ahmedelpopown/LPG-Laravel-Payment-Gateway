<?php

declare(strict_types=1);

namespace Ahmed\LaravelPayment\Payments\PayPal;

use Ahmed\LaravelPayment\Data\PayPalCredentialsData;
use Ahmed\LaravelPayment\Exceptions\PaymentGatewayException;

final class PayPalCredentialsResolver
{
    public function resolve(): PayPalCredentialsData
    {
        $clientId = config('payments.paypal.client_id');
        $clientSecret = config('payments.paypal.client_secret');
        $mode = strtolower(trim((string) config('payments.paypal.mode', 'sandbox')));
        $webhookId = config('payments.paypal.webhook_id');

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
            clientId: trim($clientId),
            clientSecret: trim($clientSecret),
            mode: $mode,
            webhookId: is_string($webhookId) ? $webhookId : null,
        );
    }
}
