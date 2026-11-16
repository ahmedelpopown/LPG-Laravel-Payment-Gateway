<?php

namespace Ahmed\LaravelPayment\Reconciliation\Contracts;

use Ahmed\LaravelPayment\Reconciliation\DTOs\ProviderPaymentPage;
use Ahmed\LaravelPayment\Reconciliation\DTOs\ProviderPaymentSnapshot;
use DateTimeInterface;

interface ReconciliationGatewayDriver
{
    public function key(): string;

    /** Return one provider payment by the exact reference stored locally. */
    public function retrieve(string $providerReference): ?ProviderPaymentSnapshot;

    /** Return provider transactions created in the requested period. */
    public function listTransactions(
        DateTimeInterface $from,
        DateTimeInterface $to,
        ?string $cursor = null,
    ): ProviderPaymentPage;
}
