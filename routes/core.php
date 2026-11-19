<?php

declare(strict_types=1);

use Ahmed\LaravelPayment\Http\Controllers\Core\PayPalPaymentWebhookController;
use Ahmed\LaravelPayment\Http\Controllers\Core\PaymentCallbackController;
use Ahmed\LaravelPayment\Http\Controllers\Core\StripePaymentWebhookController;
use Illuminate\Support\Facades\Route;

Route::middleware(['api'])->group(function (): void {
    Route::post('/payments/webhooks/stripe', StripePaymentWebhookController::class)
        ->middleware('throttle:60,1')
        ->name('payments.core.stripe.webhook');

    Route::post('/payments/webhooks/paypal', PayPalPaymentWebhookController::class)
        ->middleware('throttle:60,1')
        ->name('payments.core.paypal.webhook');
});

Route::middleware(['web'])->group(function (): void {
    Route::get('/payments/return/{provider}/{payment}', [PaymentCallbackController::class, 'complete'])
        ->whereIn('provider', ['stripe', 'paypal'])
        ->name('payments.core.return');

    Route::get('/payments/cancel/{provider}/{payment}', [PaymentCallbackController::class, 'cancel'])
        ->whereIn('provider', ['stripe', 'paypal'])
        ->name('payments.core.cancel');
});
