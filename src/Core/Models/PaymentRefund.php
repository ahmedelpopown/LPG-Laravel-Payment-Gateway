<?php

declare(strict_types=1);

namespace Ahmed\LaravelPayment\Core\Models;

use Ahmed\LaravelPayment\Enums\RefundStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class PaymentRefund extends Model
{
    protected $table = 'payment_refunds';

    protected $fillable = [
        'payment_id',
        'provider',
        'provider_refund_id',
        'idempotency_key',
        'amount',
        'currency',
        'status',
        'reason',
        'failure_message',
        'metadata',
        'requested_by_type',
        'requested_by_id',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'status' => RefundStatus::class,
            'metadata' => 'array',
            'processed_at' => 'immutable_datetime',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'payment_id');
    }

    public function requestedBy(): MorphTo
    {
        return $this->morphTo();
    }
}
