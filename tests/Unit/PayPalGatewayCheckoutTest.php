<?php

declare(strict_types=1);

use Ahmed\LaravelPayment\Core\Models\Payment;
use Ahmed\LaravelPayment\Enums\PaymentProvider;
use Ahmed\LaravelPayment\Enums\PaymentStatus;
use Ahmed\LaravelPayment\Payments\PayPal\PayPalClient;
use Ahmed\LaravelPayment\Payments\PayPal\PayPalGateway;
use Illuminate\Support\Facades\Route;
use Mockery\MockInterface;

function pendingPayPalCheckoutPayment(string $idempotencyKey): Payment
{
    $payment = new Payment();
    $payment->setRawAttributes([
        'id' => 7,
        'public_id' => 'payment-checkout-test',
        'provider' => PaymentProvider::PayPal->value,
        'provider_reference' => null,
        'idempotency_key' => $idempotencyKey,
        'amount' => '25.00',
        'currency' => 'USD',
        'status' => PaymentStatus::Pending->value,
        'metadata' => [],
    ], true);
    $payment->exists = true;

    return $payment;
}

it('uses a stable invoice ID per idempotency key and a different ID for another payment', function (): void {
    Route::get('/_test/payments/return/{provider}/{payment}', fn () => null)
        ->name('payments.core.return');
    Route::get('/_test/payments/cancel/{provider}/{payment}', fn () => null)
        ->name('payments.core.cancel');

    /** @var PayPalClient&MockInterface $client */
    $client = Mockery::mock(PayPalClient::class);
    $invoiceIds = [];

    $client->shouldReceive('createOrder')
        ->times(3)
        ->andReturnUsing(function (array $payload) use (&$invoiceIds): array {
            $invoiceIds[] = $payload['purchase_units'][0]['invoice_id'];

            return [
                'id' => 'ORDER-CHECKOUT-TEST',
                'status' => 'CREATED',
                'links' => [[
                    'rel' => 'approve',
                    'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=ORDER-CHECKOUT-TEST',
                ]],
            ];
        });

    $gateway = new PayPalGateway($client);
    $gateway->createCheckout(pendingPayPalCheckoutPayment('stable-attempt-one'));
    $gateway->createCheckout(pendingPayPalCheckoutPayment('stable-attempt-one'));
    $gateway->createCheckout(pendingPayPalCheckoutPayment('stable-attempt-two'));

    expect($invoiceIds[0])->toBe($invoiceIds[1])
        ->and($invoiceIds[0])->not->toBe($invoiceIds[2])
        ->and($invoiceIds[0])->toBe('PAYMENT-'.strtoupper(substr(
            hash('sha256', 'paypal-payment-invoice:stable-attempt-one'),
            0,
            40,
        )));
});
