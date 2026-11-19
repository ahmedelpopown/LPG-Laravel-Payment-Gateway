<?php

declare(strict_types=1);

use Ahmed\LaravelPayment\Core\Actions\RefundPayment;
use Ahmed\LaravelPayment\Core\Models\Payment;
use Ahmed\LaravelPayment\Enums\PaymentProvider;
use Ahmed\LaravelPayment\Enums\PaymentStatus;
use Ahmed\LaravelPayment\Enums\RefundStatus;
use Ahmed\LaravelPayment\Exceptions\PaymentGatewayException;
use Ahmed\LaravelPayment\Tests\Fakes\Payments\FakePaymentGateway;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

beforeEach(function (): void {
    Artisan::call('migrate', ['--database' => 'testing', '--force' => true]);
    config()->set('payments.gateways.stripe', FakePaymentGateway::class);
});

function makePaidPaymentForRefundTest(string $amount = '500.00'): Payment
{
    return Payment::query()->create([
        'provider' => PaymentProvider::Stripe,
        'provider_reference' => 'cs_test_refund_'.Str::uuid(),
        'provider_transaction_id' => 'pi_test_refund_'.Str::uuid(),
        'idempotency_key' => 'payment-refund-test-'.Str::uuid(),
        'amount' => $amount,
        'refunded_amount' => '0.00',
        'currency' => 'USD',
        'status' => PaymentStatus::Paid,
        'metadata' => [],
    ]);
}

it('records a partial refund and updates the payment balance', function (): void {
    $payment = makePaidPaymentForRefundTest();

    $refund = app(RefundPayment::class)->handle(
        payment: $payment,
        amount: '125.00',
        idempotencyKey: (string) Str::uuid(),
        reason: 'Partial test refund',
    );

    expect($refund->status)->toBe(RefundStatus::Completed)
        ->and($refund->amount)->toBe('125.00')
        ->and($payment->fresh()->status)->toBe(PaymentStatus::PartiallyRefunded)
        ->and($payment->fresh()->refunded_amount)->toBe('125.00');
});

it('records a full refund and marks the payment refunded', function (): void {
    $payment = makePaidPaymentForRefundTest('80.00');

    $refund = app(RefundPayment::class)->handle(
        payment: $payment,
        amount: '80.00',
        idempotencyKey: (string) Str::uuid(),
    );

    expect($refund->status)->toBe(RefundStatus::Completed)
        ->and($payment->fresh()->status)->toBe(PaymentStatus::Refunded)
        ->and($payment->fresh()->refunded_amount)->toBe('80.00');
});

it('rejects refund amounts above the remaining refundable balance', function (): void {
    $payment = makePaidPaymentForRefundTest('100.00');

    app(RefundPayment::class)->handle(
        payment: $payment,
        amount: '75.00',
        idempotencyKey: (string) Str::uuid(),
    );

    expect(fn () => app(RefundPayment::class)->handle(
        payment: $payment->fresh(),
        amount: '30.00',
        idempotencyKey: (string) Str::uuid(),
    ))->toThrow(PaymentGatewayException::class, 'Refund amount exceeds the remaining refundable balance.');
});
