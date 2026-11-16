<?php

namespace Ahmed\LaravelPayment\Reconciliation\Contracts;

use Ahmed\LaravelPayment\Reconciliation\DTOs\LocalPaymentSnapshot;
use DateTimeInterface;

interface LocalPaymentRepository
{
    /** @return iterable<LocalPaymentSnapshot> */
    public function iterateForReconciliation(
        string $gateway,
        DateTimeInterface $from,
        DateTimeInterface $to,
        int $chunkSize = 100,
    ): iterable;

    /** @return list<LocalPaymentSnapshot> */
    public function findAllByProviderReference(string $gateway, string $providerReference): array;

    public function findByProviderReference(string $gateway, string $providerReference): ?LocalPaymentSnapshot;
}
