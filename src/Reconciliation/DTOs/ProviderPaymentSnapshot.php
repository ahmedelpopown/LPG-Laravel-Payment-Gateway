<?php

namespace Ahmed\LaravelPayment\Reconciliation\DTOs;

use DateTimeImmutable;

final readonly class ProviderPaymentSnapshot
{
    /**
     * @param array<string, mixed> $raw A sanitized subset of provider response fields. Never store tokens or payer PII.
     */
    public function __construct(
        public string $gateway,
        public string $reference,
        public string $status,
        public int $amountMinor,
        public string $currency,
        public ?int $refundedAmountMinor = null,
        public ?DateTimeImmutable $createdAt = null,
        public array $raw = [],
    ) {
    }
}
