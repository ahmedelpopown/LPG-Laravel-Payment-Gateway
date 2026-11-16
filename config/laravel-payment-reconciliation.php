<?php

declare(strict_types=1);

use Ahmed\LaravelPayment\Core\Models\Payment;
use Ahmed\LaravelPayment\Reconciliation\Gateways\PayPal\PayPalReconciliationDriver;
use Ahmed\LaravelPayment\Reconciliation\Gateways\Stripe\StripeReconciliationDriver;

return [
    'database' => [
        'connection' => env('PAYMENT_RECONCILIATION_DB_CONNECTION'),
    ],

    'drivers' => [
        StripeReconciliationDriver::class,
        PayPalReconciliationDriver::class,
    ],

    'gateways' => [
        'stripe' => [
            'secret' => env('STRIPE_SECRET'),
            // The Core stores the Checkout Session in provider_reference.
            'reference_type' => env('PAYMENT_STRIPE_REFERENCE_TYPE', 'checkout_session'),
        ],
        'paypal' => [
            'client_id' => env('PAYPAL_CLIENT_ID'),
            'client_secret' => env('PAYPAL_CLIENT_SECRET'),
            'environment' => env('PAYPAL_MODE', 'sandbox'),
            'reference_type' => env('PAYMENT_PAYPAL_REFERENCE_TYPE', 'capture'),
        ],
    ],

    'local_payments' => [
        'model' => Payment::class,
        'repository' => null,
        'columns' => [
            'id' => 'id',
            'gateway' => 'provider',
            'provider_reference' => 'provider_reference',
            'status' => 'status',
            'amount' => 'amount',
            'amount_unit' => 'major',
            'currency' => 'currency',
            'refunded_amount' => 'refunded_amount',
            'refunded_amount_unit' => 'major',
            'created_at' => 'created_at',
        ],
        'provider_reference_by_gateway' => [
            'stripe' => 'provider_reference',
            'paypal' => 'provider_transaction_id',
        ],
    ],

    // Normalize common application statuses to provider-neutral outcomes.
    // Refund states are handled per gateway because Stripe Checkout reports the
    // underlying PaymentIntent as succeeded while PayPal exposes refund states.
    'local_status_map' => [
        'paid' => 'succeeded',
        'success' => 'succeeded',
        'successful' => 'succeeded',
        'completed' => 'succeeded',
        'complete' => 'succeeded',
        'captured' => 'succeeded',
        'processing' => 'pending',
        'cancelled' => 'canceled',
    ],

    'local_status_map_by_gateway' => [
        'stripe' => [
            'partially_refunded' => 'succeeded',
            'refunded' => 'succeeded',
        ],
        'paypal' => [
            'partially_refunded' => 'partially_refunded',
            'refunded' => 'refunded',
        ],
    ],

    'paypal' => [
        'payment_event_codes' => ['T0000', 'T0002', 'T0003', 'T0005', 'T0006', 'T0007', 'T0011', 'T0012', 'T0013', 'T0018', 'T0019'],
    ],

    'currency_scales' => [],
];
