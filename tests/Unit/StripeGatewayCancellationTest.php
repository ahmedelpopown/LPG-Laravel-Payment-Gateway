<?php

declare(strict_types=1);

use Ahmed\LaravelPayment\Core\Models\Payment;
use Ahmed\LaravelPayment\Data\PaymentResultData;
use Ahmed\LaravelPayment\Enums\PaymentProvider;
use Ahmed\LaravelPayment\Enums\PaymentStatus;
use Ahmed\LaravelPayment\Exceptions\PaymentGatewayException;
use Ahmed\LaravelPayment\Payments\Stripe\StripeClient;
use Ahmed\LaravelPayment\Payments\Stripe\StripeGateway;
use Mockery\MockInterface;

function pendingStripeCancellationPayment(array $attributes = []): Payment
{
    $payment = new Payment();
    $payment->setRawAttributes(array_merge([
        'id' => 91,
        'public_id' => 'stripe-cancel-payment-public-id',
        'provider' => PaymentProvider::Stripe->value,
        'provider_reference' => 'cs_test_cancel_123',
        'provider_transaction_id' => null,
        'idempotency_key' => 'stripe-cancel-idempotency-key',
        'amount' => '49.99',
        'currency' => 'USD',
        'status' => PaymentStatus::Pending->value,
        'metadata' => [],
    ], $attributes), true);
    $payment->exists = true;

    return $payment;
}

function stripeOpenCheckoutSession(array $overrides = []): array
{
    return array_merge([
        'id' => 'cs_test_cancel_123',
        'client_reference_id' => '91',
        'metadata' => ['payment_id' => '91'],
        'amount_total' => 4999,
        'currency' => 'usd',
        'status' => 'open',
        'payment_status' => 'unpaid',
        'payment_intent' => null,
    ], $overrides);
}

it('expires an open unpaid Stripe Checkout Session before returning cancelled', function (): void {
    /** @var StripeClient&MockInterface $client */
    $client = Mockery::mock(StripeClient::class);
    $client->shouldReceive('retrieveCheckoutSession')
        ->once()
        ->with('cs_test_cancel_123')
        ->andReturn(stripeOpenCheckoutSession());
    $client->shouldReceive('expireCheckoutSession')
        ->once()
        ->with('cs_test_cancel_123')
        ->andReturn(stripeOpenCheckoutSession(['status' => 'expired']));

    $result = (new StripeGateway($client))->cancelCheckout(pendingStripeCancellationPayment());

    expect($result)->toBeInstanceOf(PaymentResultData::class)
        ->and($result->status)->toBe(PaymentStatus::Cancelled)
        ->and($result->providerReference)->toBe('cs_test_cancel_123');
});

it('does not cancel a Checkout Session that Stripe already marked paid', function (): void {
    /** @var StripeClient&MockInterface $client */
    $client = Mockery::mock(StripeClient::class);
    $client->shouldReceive('retrieveCheckoutSession')
        ->once()
        ->andReturn(stripeOpenCheckoutSession([
            'status' => 'complete',
            'payment_status' => 'paid',
            'payment_intent' => 'pi_test_paid_123',
        ]));
    $client->shouldNotReceive('expireCheckoutSession');

    $result = (new StripeGateway($client))->cancelCheckout(pendingStripeCancellationPayment());

    expect($result->status)->toBe(PaymentStatus::Paid)
        ->and($result->transactionId)->toBe('pi_test_paid_123');
});

it('keeps a completed but unpaid asynchronous Checkout Session processing', function (): void {
    /** @var StripeClient&MockInterface $client */
    $client = Mockery::mock(StripeClient::class);
    $client->shouldReceive('retrieveCheckoutSession')
        ->once()
        ->andReturn(stripeOpenCheckoutSession([
            'status' => 'complete',
            'payment_status' => 'unpaid',
            'payment_intent' => 'pi_test_processing_123',
        ]));
    $client->shouldNotReceive('expireCheckoutSession');

    $result = (new StripeGateway($client))->cancelCheckout(pendingStripeCancellationPayment());

    expect($result->status)->toBe(PaymentStatus::Processing);
});

it('recovers a lost expiry response by retrieving the now-expired session', function (): void {
    /** @var StripeClient&MockInterface $client */
    $client = Mockery::mock(StripeClient::class);
    $client->shouldReceive('retrieveCheckoutSession')
        ->twice()
        ->andReturn(
            stripeOpenCheckoutSession(),
            stripeOpenCheckoutSession(['status' => 'expired']),
        );
    $client->shouldReceive('expireCheckoutSession')
        ->once()
        ->andThrow(new PaymentGatewayException('Connection lost.', 'stripe_connection_error'));

    $result = (new StripeGateway($client))->cancelCheckout(pendingStripeCancellationPayment());

    expect($result->status)->toBe(PaymentStatus::Cancelled);
});

it('does not mark a session cancelled when Stripe cannot confirm the result', function (): void {
    /** @var StripeClient&MockInterface $client */
    $client = Mockery::mock(StripeClient::class);
    $client->shouldReceive('retrieveCheckoutSession')
        ->twice()
        ->andReturn(stripeOpenCheckoutSession())
        ->andThrow(new PaymentGatewayException('Stripe unavailable.', 'stripe_connection_error'));
    $client->shouldReceive('expireCheckoutSession')
        ->once()
        ->andThrow(new PaymentGatewayException('Connection lost.', 'stripe_connection_error'));

    try {
        (new StripeGateway($client))->cancelCheckout(pendingStripeCancellationPayment());
        test()->fail('An unknown expiry outcome must not be reported as cancelled.');
    } catch (PaymentGatewayException $exception) {
        expect($exception->providerCode)->toBe('stripe_checkout_cancellation_outcome_unknown');
    }
});

it('rejects a Checkout Session that does not match the saved payment', function (): void {
    /** @var StripeClient&MockInterface $client */
    $client = Mockery::mock(StripeClient::class);
    $client->shouldReceive('retrieveCheckoutSession')
        ->once()
        ->andReturn(stripeOpenCheckoutSession(['amount_total' => 5000]));
    $client->shouldNotReceive('expireCheckoutSession');

    expect(fn () => (new StripeGateway($client))->cancelCheckout(pendingStripeCancellationPayment()))
        ->toThrow(PaymentGatewayException::class);
});
