<?php

declare(strict_types=1);

namespace Ahmed\LaravelPayment\Models;

use Illuminate\Database\Eloquent\Model;

final class TenantPaymentCredential extends Model
{
    protected $table = 'tenant_payment_credentials';

    protected $fillable = [
        'tenant_id',
        'paypal_client_id',
        'paypal_client_secret',
        'paypal_webhook_id',
        'paypal_mode',
        'stripe_secret_key',
        'stripe_publishable_key',
        'stripe_webhook_secret',
    ];

    public function getConnectionName(): ?string
    {
        return (string) (config('payments.connection')
            ?: config('tenancy.database.central_connection')
            ?: config('database.default', 'mysql'));
    }

    protected function casts(): array
    {
        return [
            'paypal_client_secret' => 'encrypted',
            'stripe_secret_key' => 'encrypted',
            'stripe_webhook_secret' => 'encrypted',
        ];
    }
}
