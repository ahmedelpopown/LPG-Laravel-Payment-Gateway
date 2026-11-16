<?php

namespace Ahmed\LaravelPayment\Reconciliation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReconciliationItem extends Model
{
    protected $table = 'payment_reconciliation_items';

    protected $guarded = [];

    public function getConnectionName()
    {
        return config('laravel-payment-reconciliation.database.connection') ?: parent::getConnectionName();
    }

    protected function casts(): array
    {
        return [
            'differences' => 'array',
            'provider_snapshot' => 'array',
            'resolved_at' => 'immutable_datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(ReconciliationRun::class, 'reconciliation_run_id');
    }

    public function resolutions(): HasMany
    {
        return $this->hasMany(ReconciliationResolution::class, 'reconciliation_item_id');
    }
}
