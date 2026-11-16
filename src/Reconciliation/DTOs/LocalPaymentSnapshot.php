<?php

namespace Ahmed\LaravelPayment\Reconciliation\DTOs;

use DateTimeImmutable;

final readonly class LocalPaymentSnapshot
{
    /**
     * @param array<string, mixed> $metadata Only non-sensitive identifiers should be included.
     */
    public function __construct(
        public string $id,
        public string $gateway,
        public ?string $providerReference,
        public string $status,
        public int $amountMinor,
        public string $currency,
        public ?int $refundedAmountMinor = null,
        public ?DateTimeImmutable $createdAt = null,
        public array $metadata = [],
    ) {
    }
}
