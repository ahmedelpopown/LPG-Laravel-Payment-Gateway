<?php

namespace Ahmed\LaravelPayment\Reconciliation\Services;

use Ahmed\LaravelPayment\Reconciliation\Contracts\GatewayCredentialsResolver;
use Ahmed\LaravelPayment\Reconciliation\Exceptions\ReconciliationException;

final class ConfigGatewayCredentialsResolver implements GatewayCredentialsResolver
{
    public function credentialsFor(string $gateway): array
    {
        $credentials = config("laravel-payment-reconciliation.gateways.{$gateway}", []);

        if (! is_array($credentials) || $credentials === []) {
            throw new ReconciliationException("Credentials for gateway [{$gateway}] are not configured.");
        }

        return $credentials;
    }
}
