<?php

declare(strict_types=1);

namespace Ahmed\LaravelPayment\Models;

use Ahmed\LaravelPayment\Enums\PaymentProvider;
use Ahmed\LaravelPayment\Enums\PaymentStatus;
use Ahmed\LaravelPayment\Database\Factories\BookingPaymentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BookingPayment extends Model
{
    /** @use HasFactory<BookingPaymentFactory> */
    use HasFactory;

    protected $fillable = [
        'booking_id',
        'provider',
        'provider_reference',
        'provider_transaction_id',
        'idempotency_key',
        'amount',
        'currency',
        'status',
        'checkout_url',
        'metadata',
        'paid_at',
        'refunded_at',
    ];

    protected function casts(): array
    {
        return [
            'provider' => PaymentProvider::class,
            'status' => PaymentStatus::class,
            'amount' => 'decimal:2',
            'metadata' => 'array',
            'paid_at' => 'immutable_datetime',
            'refunded_at' => 'immutable_datetime',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(config('payments.booking.booking_model') ?: config('payments.booking_model') ?: \App\Models\Booking::class);
    }

    protected static function newFactory(): BookingPaymentFactory
    {
        return BookingPaymentFactory::new();
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(BookingPaymentRefund::class);
    }
}
