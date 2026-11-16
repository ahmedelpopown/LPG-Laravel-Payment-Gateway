<?php

declare(strict_types=1);

namespace Ahmed\LaravelPayment\Models;

use Ahmed\LaravelPayment\Enums\RefundStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingPaymentRefund extends Model
{
    use HasFactory;

    protected $fillable = [
        'booking_payment_id',
        'provider',
        'provider_refund_id',
        'idempotency_key',
        'amount',
        'currency',
        'status',
        'reason',
        'failure_message',
        'metadata',
        'requested_by_employee_id',
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

    public function bookingPayment(): BelongsTo
    {
        return $this->belongsTo(BookingPayment::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(config('payments.booking.employee_model') ?: config('payments.employee_model') ?: \App\Models\Employee::class, 'requested_by_employee_id');
    }
}
