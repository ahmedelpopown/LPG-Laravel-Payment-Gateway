<?php

namespace Ahmed\LaravelPayment\Reconciliation\DTOs;

use Ahmed\LaravelPayment\Reconciliation\Enums\ReconciliationStatus;

final readonly class ComparisonResult
{
    /** @param array<string, array{local: mixed, provider: mixed}> $differences */
    public function __construct(
        public ReconciliationStatus $status,
        public array $differences = [],
    ) {
    }
}
