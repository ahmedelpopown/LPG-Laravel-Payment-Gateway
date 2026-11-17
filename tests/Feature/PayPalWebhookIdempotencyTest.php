<?php

declare(strict_types=1);

use Ahmed\LaravelPayment\Core\Webhooks\ProcessPayPalPaymentWebhook;
use Ahmed\LaravelPayment\Exceptions\PaymentGatewayException;
use Ahmed\LaravelPayment\Models\PaymentWebhookEvent;
use Illuminate\Support\Facades\Artisan;

beforeEach(function (): void {
    Artisan::call('migrate', ['--database' => 'testing', '--force' => true]);
});

it('asks PayPal to retry a duplicate event while the original delivery is still processing', function (): void {
    PaymentWebhookEvent::query()->create([
        'provider' => 'paypal',
        'event_id' => 'WH-IN-FLIGHT-ONE',
        'event_type' => 'CHECKOUT.ORDER.APPROVED',
        'status' => 'processing',
        'payload' => ['id' => 'WH-IN-FLIGHT-ONE'],
    ]);

    try {
        app(ProcessPayPalPaymentWebhook::class)->handle([
            'id' => 'WH-IN-FLIGHT-ONE',
            'event_type' => 'CHECKOUT.ORDER.APPROVED',
            'resource' => ['id' => 'ORDER-NOT-USED'],
        ]);

        test()->fail('An in-flight webhook must not be acknowledged as a completed duplicate.');
    } catch (PaymentGatewayException $exception) {
        expect($exception->providerCode)->toBe('paypal_webhook_event_in_progress')
            ->and($exception->httpStatus)->toBe(503);
    }
});

it('acknowledges a duplicate event after its first delivery reached a terminal state', function (): void {
    PaymentWebhookEvent::query()->create([
        'provider' => 'paypal',
        'event_id' => 'WH-PROCESSED-ONE',
        'event_type' => 'CUSTOMER.DISPUTE.CREATED',
        'status' => 'ignored',
        'payload' => ['id' => 'WH-PROCESSED-ONE'],
        'processed_at' => now(),
    ]);

    $result = app(ProcessPayPalPaymentWebhook::class)->handle([
        'id' => 'WH-PROCESSED-ONE',
        'event_type' => 'CUSTOMER.DISPUTE.CREATED',
        'resource' => [],
    ]);

    expect($result)->toBe('duplicate');
});
