<?php

namespace Ahmed\LaravelPayment\Reconciliation\DTOs;

final readonly class ProviderPaymentPage
{
    /** @param list<ProviderPaymentSnapshot> $items */
    public function __construct(
        public array $items,
        public ?string $nextCursor = null,
        public bool $hasMore = false,
    ) {
    }
}
