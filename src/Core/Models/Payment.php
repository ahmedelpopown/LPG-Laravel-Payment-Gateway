<?php

declare(strict_types=1);

namespace Ahmed\LaravelPayment\Core\Models;

use Ahmed\LaravelPayment\Enums\PaymentProvider;
use Ahmed\LaravelPayment\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

/**
 * Provider-agnostic payment record. It does not require a Booking, User,
 * Employee, Order, or any other application-owned table.
 */
class Payment extends Model
{
    protected $table = 'payments';

    protected $fillable = [
        'payable_type',
        'payable_id',
        'provider',
        'provider_reference',
        'provider_transaction_id',
        'idempotency_key',
        'amount',
        'refunded_amount',
        'currency',
        'status',
        'checkout_url',
        'metadata',
        'paid_at',
        'refunded_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $payment): void {
            $payment->public_id ??= (string) Str::uuid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected function casts(): array
    {
        return [
            'provider' => PaymentProvider::class,
            'status' => PaymentStatus::class,
            'amount' => 'decimal:2',
            'refunded_amount' => 'decimal:2',
            'metadata' => 'array',
            'paid_at' => 'immutable_datetime',
            'refunded_at' => 'immutable_datetime',
        ];
    }

    public function payable(): MorphTo
    {
        return $this->morphTo();
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(PaymentRefund::class, 'payment_id');
    }

    public function webhookEvents(): HasMany
    {
        return $this->hasMany(\Ahmed\LaravelPayment\Models\PaymentWebhookEvent::class, 'payment_id');
    }
}
