<?php

declare(strict_types=1);

use Ahmed\LaravelPayment\Core\Models\Payment;
use Ahmed\LaravelPayment\Data\PaymentResultData;
use Ahmed\LaravelPayment\Enums\PaymentProvider;
use Ahmed\LaravelPayment\Enums\PaymentStatus;
use Ahmed\LaravelPayment\Exceptions\PaymentGatewayException;
use Ahmed\LaravelPayment\Payments\PayPal\PayPalClient;
use Ahmed\LaravelPayment\Payments\PayPal\PayPalGateway;
use Mockery\MockInterface;

function pendingPayPalCorePayment(array $attributes = []): Payment
{
    $payment = new Payment();
    $payment->setRawAttributes(array_merge([
        'id' => 42,
        'public_id' => 'payment-public-test-id',
        'provider' => PaymentProvider::PayPal->value,
        'provider_reference' => 'ORDER-RECOVERY-TEST',
        'provider_transaction_id' => null,
        'idempotency_key' => 'payment-idempotency-test-key',
        'amount' => '125.00',
        'currency' => 'USD',
        'status' => PaymentStatus::Pending->value,
        'metadata' => [],
    ], $attributes), true);
    $payment->exists = true;

    return $payment;
}

function completedPayPalOrderWithCapture(string $status = 'COMPLETED'): array
{
    return [
        'id' => 'ORDER-RECOVERY-TEST',
        'status' => 'COMPLETED',
        'purchase_units' => [[
            'payments' => [
                'captures' => [[
                    'id' => 'CAPTURE-RECOVERED-TEST',
                    'status' => $status,
                    'amount' => [
                        'value' => '125.00',
                        'currency_code' => 'USD',
                    ],
                    'seller_receivable_breakdown' => [
                        'gross_amount' => ['value' => '125.00', 'currency_code' => 'USD'],
                        'paypal_fee' => ['value' => '4.25', 'currency_code' => 'USD'],
                        'net_amount' => ['value' => '120.75', 'currency_code' => 'USD'],
                    ],
                ]],
            ],
        ]],
    ];
}

beforeEach(function (): void {
    config()->set('payments.paypal.supported_currencies', ['USD', 'EUR', 'GBP']);
});

it('recovers an already completed capture by reading the order', function (): void {
    /** @var PayPalClient&MockInterface $client */
    $client = Mockery::mock(PayPalClient::class);
    $client->shouldReceive('captureOrder')
        ->once()
        ->with('ORDER-RECOVERY-TEST', Mockery::type('string'))
        ->andThrow(new PaymentGatewayException('Already captured.', 'ORDER_ALREADY_CAPTURED', 422));
    $client->shouldReceive('showOrder')
        ->once()
        ->with('ORDER-RECOVERY-TEST')
        ->andReturn(completedPayPalOrderWithCapture());

    $result = (new PayPalGateway($client))->capture(pendingPayPalCorePayment());

    expect($result)->toBeInstanceOf(PaymentResultData::class)
        ->and($result->status)->toBe(PaymentStatus::Paid)
        ->and($result->transactionId)->toBe('CAPTURE-RECOVERED-TEST')
        ->and($result->metadata['paypal_gross_amount'])->toBe('125.00')
        ->and($result->metadata['paypal_fee_amount'])->toBe('4.25')
        ->and($result->metadata['paypal_net_amount'])->toBe('120.75');
});

it('recovers a capture when the original request timed out after PayPal completed it', function (): void {
    /** @var PayPalClient&MockInterface $client */
    $client = Mockery::mock(PayPalClient::class);
    $client->shouldReceive('captureOrder')
        ->once()
        ->andThrow(new PaymentGatewayException('Connection timed out.', 'paypal_connection_error'));
    $client->shouldReceive('showOrder')
        ->once()
        ->andReturn(completedPayPalOrderWithCapture());

    $result = (new PayPalGateway($client))->capture(pendingPayPalCorePayment());

    expect($result->status)->toBe(PaymentStatus::Paid)
        ->and($result->transactionId)->toBe('CAPTURE-RECOVERED-TEST');
});

it('keeps the outcome unknown when a timeout is followed by an order without captures', function (): void {
    /** @var PayPalClient&MockInterface $client */
    $client = Mockery::mock(PayPalClient::class);
    $client->shouldReceive('captureOrder')
        ->once()
        ->andThrow(new PaymentGatewayException('Connection timed out.', 'paypal_connection_error'));
    $client->shouldReceive('showOrder')
        ->once()
        ->andReturn([
            'id' => 'ORDER-RECOVERY-TEST',
            'status' => 'APPROVED',
            'purchase_units' => [],
        ]);

    try {
        (new PayPalGateway($client))->capture(pendingPayPalCorePayment());
        test()->fail('The capture must not be marked successful without capture details.');
    } catch (PaymentGatewayException $exception) {
        expect($exception->providerCode)->toBe('paypal_connection_error');
    }
});

it('preserves an unknown provider capture status as processing instead of failed', function (): void {
    /** @var PayPalClient&MockInterface $client */
    $client = Mockery::mock(PayPalClient::class);
    $client->shouldReceive('captureOrder')
        ->once()
        ->andReturn(completedPayPalOrderWithCapture('NEW_PROVIDER_STATUS'));

    $result = (new PayPalGateway($client))->capture(pendingPayPalCorePayment());

    expect($result->status)->toBe(PaymentStatus::Processing);
});

it('returns an explicit unknown-outcome exception when the order lookup also fails', function (): void {
    /** @var PayPalClient&MockInterface $client */
    $client = Mockery::mock(PayPalClient::class);
    $client->shouldReceive('captureOrder')
        ->once()
        ->andThrow(new PaymentGatewayException('Connection timed out.', 'paypal_connection_error'));
    $client->shouldReceive('showOrder')
        ->once()
        ->andThrow(new PaymentGatewayException('PayPal unavailable.', 'paypal_connection_error', 503));

    try {
        (new PayPalGateway($client))->capture(pendingPayPalCorePayment());
        test()->fail('An unverified payment outcome must throw.');
    } catch (PaymentGatewayException $exception) {
        expect($exception->providerCode)->toBe('paypal_capture_outcome_unknown')
            ->and($exception->httpStatus)->toBe(503);
    }
});
