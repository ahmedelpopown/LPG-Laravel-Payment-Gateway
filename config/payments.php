<?php

declare(strict_types=1);

use Ahmed\LaravelPayment\Payments\PayPal\PayPalGateway;
use Ahmed\LaravelPayment\Payments\Stripe\StripeGateway;

return [
    // Optional database connection used by the package installation command.
    'connection' => env('PAYMENTS_CONNECTION'),

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

    'redirects' => [
        'success_url' => env('PAYMENTS_SUCCESS_URL'),
        'cancel_url' => env('PAYMENTS_CANCEL_URL'),
    ],

    'routes' => [
        'core_auto_register' => true,
    ],
];
