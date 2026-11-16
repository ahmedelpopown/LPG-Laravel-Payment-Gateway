<?php

declare(strict_types=1);

use Ahmed\LaravelPayment\Core\Actions\ConfirmPayment;
use Ahmed\LaravelPayment\Core\Models\Payment;
use Ahmed\LaravelPayment\Enums\PaymentProvider;
use Ahmed\LaravelPayment\Enums\PaymentStatus;
use Ahmed\LaravelPayment\Exceptions\PaymentGatewayException;
use Ahmed\LaravelPayment\Http\Controllers\Core\PaymentCallbackController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Mockery\MockInterface;

it('does not turn an ambiguous PayPal callback into a failed payment or another checkout', function (): void {
    Artisan::call('migrate', ['--database' => 'testing', '--force' => true]);

    $payment = Payment::query()->create([
        'provider' => PaymentProvider::PayPal,
        'provider_reference' => 'ORDER-CALLBACK-RECOVERY',
        'idempotency_key' => 'callback-recovery-idempotency-key',
        'amount' => '15.00',
        'currency' => 'USD',
        'status' => PaymentStatus::Pending,
    ]);

    /** @var ConfirmPayment&MockInterface $confirm */
    $confirm = Mockery::mock(ConfirmPayment::class);
    $confirm->shouldReceive('handle')
        ->once()
        ->andThrow(new PaymentGatewayException(
            'The capture response timed out.',
            'paypal_capture_outcome_unknown',
            503,
        ));

    $request = Request::create('/payments/return/paypal/'.$payment->public_id, 'GET', [
        'token' => 'ORDER-CALLBACK-RECOVERY',
    ]);

    $response = (new PaymentCallbackController())->complete(
        'paypal',
        $payment,
        $request,
        $confirm,
    );

    $payload = $response->getData(true);

    expect($response->getStatusCode())->toBe(503)
        ->and($payload['status'])->toBe(PaymentStatus::Pending->value)
        ->and($payload['payment_id'])->toBe($payment->public_id)
        ->and($payment->fresh()->status)->toBe(PaymentStatus::Pending);
});
