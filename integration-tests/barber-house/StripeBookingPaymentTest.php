<?php

declare(strict_types=1);

use Ahmed\LaravelPayment\Data\CheckoutSessionData;
use Ahmed\LaravelPayment\Enums\PaymentProvider;
use Ahmed\LaravelPayment\Enums\PaymentStatus;
use Ahmed\LaravelPayment\Exceptions\PaymentGatewayException;
use App\Models\Booking;
use Ahmed\LaravelPayment\Models\BookingPayment;
use Ahmed\LaravelPayment\Payments\Stripe\StripeClient;
use Ahmed\LaravelPayment\Payments\Stripe\StripeGateway;
use Illuminate\Support\Facades\Route;
use Mockery\MockInterface;

function createStripeBookingPayment(array $attributes = []): BookingPayment
{
    $booking = Booking::factory()->create();

    return BookingPayment::factory()->create(array_merge([
        'booking_id' => $booking->getKey(),
        'provider' => PaymentProvider::Stripe,
        'provider_reference' => 'cs_test_barber_checkout_1',
        'provider_transaction_id' => null,
        'amount' => '125.00',
        'currency' => 'USD',
        'status' => PaymentStatus::Pending,
    ], $attributes));
}

it('registers Stripe as a gateway and exposes tenant checkout, return, cancel and webhook routes', function (): void {
    expect(config('payments.gateways.stripe'))->toBe(StripeGateway::class)
        ->and(Route::getRoutes()->getByName('customer.bookings.payments.stripe'))->not->toBeNull()
        ->and(Route::getRoutes()->getByName('payments.stripe.return'))->not->toBeNull()
        ->and(Route::getRoutes()->getByName('payments.stripe.cancel'))->not->toBeNull();

    $webhookRoute = Route::getRoutes()->getByName('payments.stripe.webhook');

    expect($webhookRoute)->not->toBeNull()
        ->and($webhookRoute->methods())->toContain('POST')
        ->and($webhookRoute->middleware())->not->toContain('auth:customer')
        ->and($webhookRoute->middleware())->not->toContain(config('payments.routes.tenant_active_middleware'));
});

it('creates a Stripe Checkout Session with the persisted booking amount and metadata', function (): void {
    $payment = createStripeBookingPayment();

    /** @var StripeClient&MockInterface $client */
    $client = Mockery::mock(StripeClient::class);
    $client->shouldReceive('createCheckoutSession')
        ->once()
        ->with(Mockery::on(function (array $payload) use ($payment): bool {
            return $payload['mode'] === 'payment'
                && $payload['payment_method_types'] === ['card']
                && $payload['client_reference_id'] === (string) $payment->getKey()
                && $payload['line_items'][0]['price_data']['currency'] === 'usd'
                && $payload['line_items'][0]['price_data']['unit_amount'] === 12500
                && $payload['metadata']['booking_payment_id'] === (string) $payment->getKey()
                && str_contains($payload['success_url'], '{CHECKOUT_SESSION_ID}');
        }), Mockery::type('string'))
        ->andReturn([
            'id' => 'cs_test_barber_checkout_1',
            'url' => 'https://checkout.stripe.com/c/pay/cs_test_barber_checkout_1',
            'status' => 'open',
            'payment_status' => 'unpaid',
        ]);

    $checkout = (new StripeGateway($client))->createCheckout($payment);

    expect($checkout)->toBeInstanceOf(CheckoutSessionData::class)
        ->and($checkout->providerReference)->toBe('cs_test_barber_checkout_1')
        ->and($checkout->checkoutUrl)->toBe('https://checkout.stripe.com/c/pay/cs_test_barber_checkout_1');
});

it('confirms a Stripe Checkout Session only when its amount and currency match the booking payment', function (): void {
    $payment = createStripeBookingPayment();

    /** @var StripeClient&MockInterface $client */
    $client = Mockery::mock(StripeClient::class);
    $client->shouldReceive('retrieveCheckoutSession')
        ->once()
        ->with('cs_test_barber_checkout_1')
        ->andReturn([
            'id' => 'cs_test_barber_checkout_1',
            'client_reference_id' => (string) $payment->getKey(),
            'metadata' => ['booking_payment_id' => (string) $payment->getKey()],
            'amount_total' => 12500,
            'currency' => 'usd',
            'status' => 'complete',
            'payment_status' => 'paid',
            'payment_intent' => 'pi_test_barber_payment_1',
        ]);

    $result = (new StripeGateway($client))->capture($payment);

    expect($result->status)->toBe(PaymentStatus::Paid)
        ->and($result->amount)->toBe('125.00')
        ->and($result->currency)->toBe('USD')
        ->and($result->transactionId)->toBe('pi_test_barber_payment_1');
});

it('rejects a Stripe Session when its amount does not equal the saved booking amount', function (): void {
    $payment = createStripeBookingPayment();

    /** @var StripeClient&MockInterface $client */
    $client = Mockery::mock(StripeClient::class);
    $client->shouldReceive('retrieveCheckoutSession')
        ->once()
        ->andReturn([
            'id' => 'cs_test_barber_checkout_1',
            'client_reference_id' => (string) $payment->getKey(),
            'metadata' => ['booking_payment_id' => (string) $payment->getKey()],
            'amount_total' => 12400,
            'currency' => 'usd',
            'status' => 'complete',
            'payment_status' => 'paid',
            'payment_intent' => 'pi_test_barber_payment_1',
        ]);

    expect(fn () => (new StripeGateway($client))->capture($payment))
        ->toThrow(PaymentGatewayException::class, 'amount or currency');
});

it('allows EGP as a Stripe presentment currency while PayPal keeps its own currency restrictions', function (): void {
    $gateway = new StripeGateway(Mockery::mock(StripeClient::class));

    expect($gateway->supportsCurrency('EGP'))->toBeTrue()
        ->and($gateway->supportsCurrency('USD'))->toBeTrue()
        ->and($gateway->supportsCurrency('JPY'))->toBeFalse();
});
