<?php

declare(strict_types=1);

use Ahmed\LaravelPayment\Http\Controllers\Payments\CheckoutController;
use Ahmed\LaravelPayment\Http\Controllers\Payments\PayPalReturnController;
use Ahmed\LaravelPayment\Http\Controllers\Payments\PayPalWebhookController;
use Ahmed\LaravelPayment\Http\Controllers\Payments\StripeCheckoutController;
use Ahmed\LaravelPayment\Http\Controllers\Payments\StripeReturnController;
use Ahmed\LaravelPayment\Http\Controllers\Payments\StripeWebhookController;
use Ahmed\LaravelPayment\Livewire\PaymentIndex;
use Ahmed\LaravelPayment\Livewire\PaymentMethodSettings;
use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;

Route::middleware([
    'api',
    InitializeTenancyByDomain::class,
    PreventAccessFromCentralDomains::class,
])->group(function (): void {
    Route::post('/payments/paypal/webhook', PayPalWebhookController::class)
        ->middleware('throttle:60,1')
        ->name('payments.paypal.webhook');

    Route::post('/payments/stripe/webhook', StripeWebhookController::class)
        ->middleware('throttle:60,1')
        ->name('payments.stripe.webhook');
});

$activeTenantMiddleware = config('payments.routes.tenant_active_middleware');
$tenantWebMiddleware = [
    'web',
    InitializeTenancyByDomain::class,
    PreventAccessFromCentralDomains::class,
];

if (is_string($activeTenantMiddleware) && $activeTenantMiddleware !== '') {
    $tenantWebMiddleware[] = $activeTenantMiddleware;
}

Route::middleware($tenantWebMiddleware)->group(function (): void {
    Route::middleware('auth:'.config('payments.auth.customer_guard', 'customer'))->group(function (): void {
        Route::post('/bookings/{booking}/payments/paypal', CheckoutController::class)
            ->middleware('throttle:5,1')
            ->name('customer.bookings.payments.paypal');

        Route::get('/payments/paypal/return/{payment}', [PayPalReturnController::class, 'complete'])
            ->middleware('throttle:10,1')
            ->name('payments.paypal.return');

        Route::get('/payments/paypal/cancel/{payment}', [PayPalReturnController::class, 'cancel'])
            ->middleware('throttle:10,1')
            ->name('payments.paypal.cancel');

        Route::post('/bookings/{booking}/payments/stripe', StripeCheckoutController::class)
            ->middleware('throttle:5,1')
            ->name('customer.bookings.payments.stripe');

        Route::get('/payments/stripe/return/{payment}', [StripeReturnController::class, 'complete'])
            ->middleware('throttle:10,1')
            ->name('payments.stripe.return');

        Route::get('/payments/stripe/cancel/{payment}', [StripeReturnController::class, 'cancel'])
            ->middleware('throttle:10,1')
            ->name('payments.stripe.cancel');
    });

    Route::prefix('admin')
        ->middleware([
            'auth:'.config('payments.auth.employee_guard', 'employee'),
            'role:'.config('payments.auth.admin_role', 'admin'),
        ])
        ->name('admin.')
        ->group(function (): void {
            Route::get('/payments', PaymentIndex::class)->name('payments.index');
            Route::get('/settings/payment-methods', PaymentMethodSettings::class)->name('settings.payment-methods');
        });
});
