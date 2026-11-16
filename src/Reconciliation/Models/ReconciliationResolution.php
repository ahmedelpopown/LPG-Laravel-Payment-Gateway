<?php

namespace Ahmed\LaravelPayment\Reconciliation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReconciliationResolution extends Model
{
    protected $table = 'payment_reconciliation_resolutions';

    protected $guarded = [];

    public function getConnectionName()
    {
        return config('laravel-payment-reconciliation.database.connection') ?: parent::getConnectionName();
    }

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(ReconciliationItem::class, 'reconciliation_item_id');
    }
}
