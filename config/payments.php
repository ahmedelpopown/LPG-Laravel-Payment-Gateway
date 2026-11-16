<?php

declare(strict_types=1);

use Ahmed\LaravelPayment\Payments\PayPal\PayPalGateway;
use Ahmed\LaravelPayment\Payments\Stripe\StripeGateway;

return [
    // In a normal Laravel app this is the default connection. In a Stancl app
    // configure this to the central connection for tenant credential storage.
    'connection' => env('PAYMENTS_CONNECTION'),

    // Legacy Booking integration keys are null by default and must be set by an app that opts in.
    'booking_model' => null,
    'employee_model' => null,
    'booking_currency' => strtoupper((string) env('BOOKING_PAYMENT_CURRENCY', 'USD')),

    'gateways' => [
        'paypal' => PayPalGateway::class,
        'stripe' => StripeGateway::class,
    ],

    'stripe' => [
        'secret_key' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        'product_name' => env('STRIPE_PRODUCT_NAME', env('APP_NAME', 'Laravel payment')),
        'supported_currencies' => [
            'AED', 'AUD', 'BGN', 'BRL', 'CAD', 'CHF', 'CNY', 'CZK', 'DKK',
            'EGP', 'EUR', 'GBP', 'HKD', 'ILS', 'INR', 'MXN',
            'MYR', 'NOK', 'NZD', 'PHP', 'PLN', 'RON', 'SAR', 'SEK', 'SGD',
            'THB', 'TRY', 'USD', 'ZAR',
        ],
    ],

    'paypal' => [
        'timeout' => (int) env('PAYPAL_TIMEOUT', 20),
        'connect_timeout' => (int) env('PAYPAL_CONNECT_TIMEOUT', 10),
        'brand_name' => env('PAYPAL_BRAND_NAME', env('APP_NAME', 'Laravel')),
        'client_id' => env('PAYPAL_CLIENT_ID'),
        'client_secret' => env('PAYPAL_CLIENT_SECRET'),
        'webhook_id' => env('PAYPAL_WEBHOOK_ID'),
        'mode' => env('PAYPAL_MODE', 'sandbox'),
        'supported_currencies' => [
            'AUD', 'BRL', 'CAD', 'CNY', 'CZK', 'DKK', 'EUR', 'GBP', 'HKD',
            'ILS', 'MYR', 'MXN', 'NZD', 'NOK', 'PHP', 'PLN', 'SGD', 'SEK',
            'CHF', 'THB', 'USD',
        ],
    ],

    // Optional browser destinations after a provider redirect. If omitted,
    // the package callback returns a small JSON response.
    'redirects' => [
        'success_url' => env('PAYMENTS_SUCCESS_URL'),
        'cancel_url' => env('PAYMENTS_CANCEL_URL'),
    ],

    'routes' => [
        'core_auto_register' => true,
        'booking_auto_register' => false, // Deprecated; use payments.booking.enabled instead.
        'tenant_active_middleware' => null,
    ],

    // Booking integration is disabled by default. A consuming project may
    // override these with its own models/services and install it explicitly.
    'booking' => [
        'enabled' => false,
        'booking_model' => null,
        'employee_model' => null,
        'tenant_resolver' => null,
        'price_calculator' => null,
        'feature_gate' => null,
        'currency' => strtoupper((string) env('BOOKING_PAYMENT_CURRENCY', 'USD')),
    ],

    'auth' => [
        'customer_guard' => 'web',
        'employee_guard' => 'web',
        'admin_role' => 'admin',
    ],
];
