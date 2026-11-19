<?php

declare(strict_types=1);

use Ahmed\LaravelPayment\Core\Models\Payment;
use Ahmed\LaravelPayment\Enums\PaymentProvider;
use Ahmed\LaravelPayment\Enums\PaymentStatus;
use Ahmed\LaravelPayment\Events\PaymentStatusChanged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;

it('dispatches the package event when a payment status changes', function (): void {
    Artisan::call('migrate', ['--database' => 'testing', '--force' => true]);
    Event::fake([PaymentStatusChanged::class]);

    $payment = Payment::query()->create([
        'provider' => PaymentProvider::Stripe,
        'idempotency_key' => 'observer-test-payment-001',
        'amount' => '50.00',
        'refunded_amount' => '0.00',
        'currency' => 'USD',
        'status' => PaymentStatus::Pending,
    ]);

    $payment->forceFill(['status' => PaymentStatus::Paid])->save();

    Event::assertDispatched(
        PaymentStatusChanged::class,
        fn (PaymentStatusChanged $event): bool => $event->payment->is($payment)
            && $event->payment->status === PaymentStatus::Paid,
    );
});
