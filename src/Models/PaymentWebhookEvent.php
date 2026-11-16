<?php

declare(strict_types=1);

namespace Ahmed\LaravelPayment\Models;

use Ahmed\LaravelPayment\Core\Models\Payment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentWebhookEvent extends Model
{
    protected $fillable = [
        'provider',
        'event_id',
        'event_type',
        'payment_id',
        'booking_payment_id',
        'status',
        'error_code',
        'payload',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'processed_at' => 'immutable_datetime',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'payment_id');
    }

    /** Backward compatibility for the optional Booking integration. */
    public function bookingPayment(): BelongsTo
    {
        return $this->belongsTo(BookingPayment::class, 'booking_payment_id');
    }
}
