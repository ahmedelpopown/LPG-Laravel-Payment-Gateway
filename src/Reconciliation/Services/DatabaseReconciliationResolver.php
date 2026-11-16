<?php

namespace Ahmed\LaravelPayment\Reconciliation\Services;

use Ahmed\LaravelPayment\Reconciliation\Contracts\ReconciliationResolver;
use Ahmed\LaravelPayment\Reconciliation\Models\ReconciliationItem;
use InvalidArgumentException;

final class DatabaseReconciliationResolver implements ReconciliationResolver
{
    public function resolve(
        ReconciliationItem $item,
        string $resolution,
        ?string $notes = null,
        string|int|null $resolvedBy = null,
    ): void {
        if (trim($resolution) === '') {
            throw new InvalidArgumentException('A resolution description is required.');
        }

        $connection = $item->getConnection();

        $connection->transaction(function () use ($item, $resolution, $notes, $resolvedBy): void {
            $locked = $item->newQuery()->lockForUpdate()->findOrFail($item->getKey());

            $locked->resolutions()->create([
                'resolution' => $resolution,
                'notes' => $notes,
                'resolved_by' => $resolvedBy !== null ? (string) $resolvedBy : null,
            ]);

            $locked->forceFill([
                'resolution_status' => 'resolved',
                'resolved_at' => now(),
            ])->save();
        });
    }
}
