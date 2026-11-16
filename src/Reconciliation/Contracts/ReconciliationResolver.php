<?php

namespace Ahmed\LaravelPayment\Reconciliation\Contracts;

use Ahmed\LaravelPayment\Reconciliation\Models\ReconciliationItem;

interface ReconciliationResolver
{
    public function resolve(
        ReconciliationItem $item,
        string $resolution,
        ?string $notes = null,
        string|int|null $resolvedBy = null,
    ): void;
}
