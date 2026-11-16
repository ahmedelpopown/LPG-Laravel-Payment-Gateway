<?php

namespace Ahmed\LaravelPayment\Reconciliation\Contracts;

interface GatewayCredentialsResolver
{
    /** @return array<string, mixed> */
    public function credentialsFor(string $gateway): array;
}
