<?php

namespace Ahmed\LaravelPayment\Reconciliation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReconciliationRun extends Model
{
    protected $table = 'payment_reconciliation_runs';

    protected $guarded = [];

    public function getConnectionName()
    {
        return config('laravel-payment-reconciliation.database.connection') ?: parent::getConnectionName();
    }

    protected function casts(): array
    {
        return [
            'period_from' => 'immutable_datetime',
            'period_to' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'summary' => 'array',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(ReconciliationItem::class, 'reconciliation_run_id');
    }
}
