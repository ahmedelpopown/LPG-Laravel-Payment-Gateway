<?php

declare(strict_types=1);

use Ahmed\LaravelPayment\Core\Actions\FinalizePayment;
use Ahmed\LaravelPayment\Core\Models\Payment;
use Ahmed\LaravelPayment\Data\PaymentResultData;
use Ahmed\LaravelPayment\Enums\PaymentProvider;
use Ahmed\LaravelPayment\Enums\PaymentStatus;
use Illuminate\Support\Facades\Artisan;

beforeEach(function (): void {
    Artisan::call('migrate', ['--database' => 'testing', '--force' => true]);
});

function makePendingPaymentForCancellationTest(): Payment
{
    return Payment::query()->create([
        'provider' => PaymentProvider::Stripe,
        'provider_reference' => 'cs_test_confirmed_expired',
        'idempotency_key' => 'cancel-finalize-'.bin2hex(random_bytes(8)),
        'amount' => '49.99',
        'refunded_amount' => '0.00',
        'currency' => 'USD',
        'status' => PaymentStatus::Pending,
        'metadata' => [],
    ]);
}

it('stores cancelled only when a provider cancellation result is finalized', function (): void {
    $payment = makePendingPaymentForCancellationTest();

    $cancelled = app(FinalizePayment::class)->handle($payment, new PaymentResultData(
        status: PaymentStatus::Cancelled,
        amount: '49.99',
        currency: 'USD',
        providerReference: 'cs_test_confirmed_expired',
        metadata: ['stripe_session_status' => 'expired', 'stripe_payment_status' => 'unpaid'],
    ));

    expect($cancelled->fresh()->status)->toBe(PaymentStatus::Cancelled);
});

it('does not let a late non-paid event reopen a cancelled payment', function (): void {
    $payment = makePendingPaymentForCancellationTest();
    $finalizer = app(FinalizePayment::class);

    $payment = $finalizer->handle($payment, new PaymentResultData(
        status: PaymentStatus::Cancelled,
        amount: '49.99',
        currency: 'USD',
        providerReference: 'cs_test_confirmed_expired',
    ));

    $result = $finalizer->handle($payment, new PaymentResultData(
        status: PaymentStatus::Processing,
        amount: '49.99',
        currency: 'USD',
        providerReference: 'cs_test_confirmed_expired',
    ));

    expect($result->fresh()->status)->toBe(PaymentStatus::Cancelled);
});
