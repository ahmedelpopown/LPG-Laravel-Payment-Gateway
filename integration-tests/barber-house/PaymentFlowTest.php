<?php

declare(strict_types=1);

use Ahmed\LaravelPayment\Actions\Payments\ConfirmPayPalPayment;
use Ahmed\LaravelPayment\Actions\Payments\StartBookingPayment;
use Ahmed\LaravelPayment\Actions\Payments\ProcessPayPalWebhook;
use Ahmed\LaravelPayment\Enums\PaymentProvider;
use Ahmed\LaravelPayment\Enums\PaymentStatus;
use Ahmed\LaravelPayment\Exceptions\PaymentGatewayException;
use Ahmed\LaravelPayment\Http\Controllers\Payments\CheckoutController;
use Ahmed\LaravelPayment\Http\Controllers\Payments\PayPalReturnController;
use Ahmed\LaravelPayment\Http\Controllers\Payments\PayPalWebhookController;
use App\Models\Booking;
use Ahmed\LaravelPayment\Models\BookingPayment;
use Ahmed\LaravelPayment\Models\PaymentWebhookEvent;
use App\Models\Service;
use App\Models\SubService;
use App\Models\Tenant;
use App\Models\User;
use Ahmed\LaravelPayment\Data\PayPalCredentialsData;
use Ahmed\LaravelPayment\Payments\PayPal\PayPalClient;
use Ahmed\LaravelPayment\Payments\PayPal\PayPalCredentialsResolver;
use Ahmed\LaravelPayment\Payments\PayPal\PayPalGateway;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\Request as ClientRequest;
use App\Services\SubscriptionService;
use App\Services\CurrentTenantContext;
use Ahmed\LaravelPayment\Payments\PayPal\PayPalWebhookVerifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Mockery\MockInterface;
use Ahmed\LaravelPayment\Tests\Fakes\Payments\FakePaymentGateway;

function makePaymentCaptureWebhookEvent(
    string $eventId,
    string $orderId,
    string $captureId = 'PAYPAL-CAPTURE-TEST-1',
    string $amount = '125.00',
    string $currency = 'USD',
    string $eventType = 'PAYMENT.CAPTURE.COMPLETED',
): array {
    return [
        'id' => $eventId,
        'event_type' => $eventType,
        'resource_type' => 'capture',
        'resource' => [
            'id' => $captureId,
            'status' => match ($eventType) {
                'PAYMENT.CAPTURE.PENDING' => 'PENDING',
                'PAYMENT.CAPTURE.DENIED' => 'DENIED',
                default => 'COMPLETED',
            },
            'amount' => [
                'value' => $amount,
                'currency_code' => $currency,
            ],
            'supplementary_data' => [
                'related_ids' => [
                    'order_id' => $orderId,
                ],
            ],
        ],
    ];
}

function createPendingPayPalBookingPayment(array $attributes = []): BookingPayment
{
    $booking = Booking::factory()->create();

    return BookingPayment::factory()->create(array_merge([
        'booking_id' => $booking->getKey(),
        'provider' => PaymentProvider::PayPal,
        'provider_reference' => 'PAYPAL-ORDER-TEST-1',
        'provider_transaction_id' => null,
        'amount' => '125.00',
        'currency' => 'USD',
        'status' => PaymentStatus::Processing,
    ], $attributes));
}

it('completes the full booking payment flow from the start action to final paid state', function (): void {
    config()->set('payments.booking_currency', 'USD');
    config()->set('payments.gateways.paypal', FakePaymentGateway::class);

    $customer = User::factory()->create();
    $booking = Booking::factory()->create(['user_id' => $customer->getKey()]);
    $service = Service::factory()->create();
    $subService = SubService::factory()->for($service)->pricedAt('125.00')->create();
    $booking->services()->attach($subService->getKey(), ['price' => '125.00']);

    $tenant = new Tenant();
    $tenant->setAttribute('id', 'tenant-payment-flow-test');

    $tenantContext = Mockery::mock(CurrentTenantContext::class);
    $tenantContext->shouldReceive('current')->once()->andReturn($tenant);
    app()->instance(CurrentTenantContext::class, $tenantContext);

    $subscriptionService = Mockery::mock(SubscriptionService::class);
    $subscriptionService->shouldReceive('hasFeature')
        ->once()
        ->with($tenant, 'online_payment')
        ->andReturnTrue();
    app()->instance(SubscriptionService::class, $subscriptionService);

    $startedPayment = app(StartBookingPayment::class)->handle(
        $booking,
        $customer,
        PaymentProvider::PayPal,
    );

    expect($startedPayment->amount)->toBe('125.00')
        ->and($startedPayment->currency)->toBe('USD')
        ->and($startedPayment->status)->toBe(PaymentStatus::Pending)
        ->and($startedPayment->checkout_url)->toBe('https://payments.test/checkout/'.$startedPayment->getKey());

    $confirmed = app(ConfirmPayPalPayment::class)->handle(
        $startedPayment->fresh(),
        $customer,
        (string) $startedPayment->provider_reference,
    );

    expect($confirmed->status)->toBe(PaymentStatus::Paid)
        ->and($confirmed->provider_transaction_id)->toBe('fake-transaction-'.$startedPayment->getKey())
        ->and($confirmed->paid_at)->not->toBeNull();
});

it('keeps the booking payments dashboard inside the tenant admin area', function (): void {
    $route = Route::getRoutes()->getByName('admin.payments.index');

    expect($route)->not->toBeNull()
        ->and($route->methods())->toContain('GET')
        ->and($route->middleware())->toContain('auth:employee')
        ->and($route->middleware())->toContain('role:admin');
});

it('rejects EGP before creating a booking payment attempt', function (): void {
    config()->set('payments.booking_currency', 'EGP');
    config()->set('payments.gateways.paypal', PayPalGateway::class);

    $customer = User::factory()->create();
    $booking = Booking::factory()->create(['user_id' => $customer->getKey()]);

    $tenant = new Tenant();
    $tenant->setAttribute('id', 'tenant-egp-currency-guard-test');

    $tenantContext = Mockery::mock(CurrentTenantContext::class);
    $tenantContext->shouldReceive('current')->once()->andReturn($tenant);
    app()->instance(CurrentTenantContext::class, $tenantContext);

    $subscriptionService = Mockery::mock(SubscriptionService::class);
    $subscriptionService->shouldReceive('hasFeature')
        ->once()
        ->with($tenant, 'online_payment')
        ->andReturnTrue();
    app()->instance(SubscriptionService::class, $subscriptionService);

    try {
        app(StartBookingPayment::class)->handle($booking, $customer, PaymentProvider::PayPal);
        $this->fail('PayPal must reject EGP before creating a payment attempt.');
    } catch (PaymentGatewayException $exception) {
        expect($exception->providerCode)->toBe('payment_currency_not_supported');
    }

    expect(BookingPayment::query()->count())->toBe(0);
});

it('runs the complete checkout and capture flow through the real PayPal gateway and HTTP client with fake HTTP responses', function (): void {
    config()->set('payments.booking_currency', 'USD');
    config()->set('payments.gateways.paypal', PayPalGateway::class);

    $customer = User::factory()->create();
    $booking = Booking::factory()->create(['user_id' => $customer->getKey()]);
    $service = Service::factory()->create();
    $subService = SubService::factory()->for($service)->pricedAt('125.00')->create();
    $booking->services()->attach($subService->getKey(), ['price' => '125.00']);

    $tenant = new Tenant();
    $tenant->setAttribute('id', 'tenant-real-paypal-flow-test');

    $tenantContext = Mockery::mock(CurrentTenantContext::class);
    $tenantContext->shouldReceive('current')->once()->andReturn($tenant);
    app()->instance(CurrentTenantContext::class, $tenantContext);

    $subscriptionService = Mockery::mock(SubscriptionService::class);
    $subscriptionService->shouldReceive('hasFeature')
        ->once()
        ->with($tenant, 'online_payment')
        ->andReturnTrue();
    app()->instance(SubscriptionService::class, $subscriptionService);

    $credentials = new PayPalCredentialsData(
        tenantId: 'tenant-real-paypal-flow-test',
        clientId: 'paypal-client-test',
        clientSecret: 'paypal-secret-test',
        mode: 'sandbox',
        webhookId: 'WH-TEST-REAL-FLOW',
    );

    $credentialsResolver = Mockery::mock(PayPalCredentialsResolver::class);
    $credentialsResolver->shouldReceive('resolve')->twice()->andReturn($credentials);

    $client = new PayPalClient(
        Http::getFacadeRoot(),
        Cache::store('array'),
        $credentialsResolver,
    );

    app()->instance(PayPalGateway::class, new PayPalGateway($client));

    Http::fake([
        '*api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response([
            'access_token' => 'real-flow-access-token',
            'expires_in' => 3600,
            'token_type' => 'Bearer',
        ]),
        '*api-m.sandbox.paypal.com/v2/checkout/orders' => Http::response([
            'id' => 'REAL-FLOW-ORDER-1',
            'status' => 'PAYER_ACTION_REQUIRED',
            'links' => [[
                'rel' => 'payer-action',
                'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=REAL-FLOW-ORDER-1',
            ]],
        ], 201),
        '*api-m.sandbox.paypal.com/v2/checkout/orders/REAL-FLOW-ORDER-1/capture' => Http::response([
            'id' => 'REAL-FLOW-ORDER-1',
            'status' => 'COMPLETED',
            'purchase_units' => [[
                'payments' => [
                    'captures' => [[
                        'id' => 'REAL-FLOW-CAPTURE-1',
                        'status' => 'COMPLETED',
                        'amount' => [
                            'value' => '125.00',
                            'currency_code' => 'USD',
                        ],
                    ]],
                ],
            ]],
        ]),
    ]);

    $payment = app(StartBookingPayment::class)->handle(
        $booking,
        $customer,
        PaymentProvider::PayPal,
    );

    expect($payment->provider_reference)->toBe('REAL-FLOW-ORDER-1')
        ->and($payment->checkout_url)->toBe('https://www.sandbox.paypal.com/checkoutnow?token=REAL-FLOW-ORDER-1')
        ->and($payment->status)->toBe(PaymentStatus::Pending);

    $confirmed = app(ConfirmPayPalPayment::class)->handle(
        $payment,
        $customer,
        'REAL-FLOW-ORDER-1',
    );

    expect($confirmed->status)->toBe(PaymentStatus::Paid)
        ->and($confirmed->provider_transaction_id)->toBe('REAL-FLOW-CAPTURE-1')
        ->and($confirmed->paid_at)->not->toBeNull();

    Http::assertSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), '/v1/oauth2/token'));
    Http::assertSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), '/v2/checkout/orders')
        && $request->hasHeader('PayPal-Request-Id'));
    Http::assertSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), '/v2/checkout/orders/REAL-FLOW-ORDER-1/capture')
        && $request->body() === '{}'
        && $request->hasHeader('PayPal-Request-Id'));
});

it('completes the customer HTTP controller flow from checkout redirect to paid result', function (): void {
    config()->set('payments.booking_currency', 'USD');
    config()->set('payments.gateways.paypal', FakePaymentGateway::class);

    $customer = User::factory()->create();
    $booking = Booking::factory()->create(['user_id' => $customer->getKey()]);
    $service = Service::factory()->create();
    $subService = SubService::factory()->for($service)->pricedAt('125.00')->create();
    $booking->services()->attach($subService->getKey(), ['price' => '125.00']);

    $tenant = new Tenant();
    $tenant->setAttribute('id', 'tenant-payment-controller-flow-test');

    $tenantContext = Mockery::mock(CurrentTenantContext::class);
    $tenantContext->shouldReceive('current')->once()->andReturn($tenant);
    app()->instance(CurrentTenantContext::class, $tenantContext);

    $subscriptionService = Mockery::mock(SubscriptionService::class);
    $subscriptionService->shouldReceive('hasFeature')
        ->once()
        ->with($tenant, 'online_payment')
        ->andReturnTrue();
    app()->instance(SubscriptionService::class, $subscriptionService);

    $this->actingAs($customer, 'customer');

    $checkoutResponse = app(CheckoutController::class)(
        (string) $booking->getKey(),
        app(StartBookingPayment::class),
    );

    $payment = BookingPayment::query()->where('booking_id', $booking->getKey())->sole();

    expect($checkoutResponse->getTargetUrl())
        ->toBe('https://payments.test/checkout/'.$payment->getKey())
        ->and($payment->status)->toBe(PaymentStatus::Pending)
        ->and($payment->provider_reference)->toStartWith('fake-order-');

    $returnRequest = Request::create(
        '/payments/paypal/return/'.$payment->getKey().'?token='.$payment->provider_reference,
        'GET',
    );

    $returnResponse = app(PayPalReturnController::class)->complete(
        $returnRequest,
        $payment,
        app(ConfirmPayPalPayment::class),
    );

    expect($returnResponse->getTargetUrl())
        ->toBe(route('customer.profile.bookings'))
        ->and($payment->fresh()->status)->toBe(PaymentStatus::Paid)
        ->and($payment->fresh()->provider_transaction_id)->toBe('fake-transaction-'.$payment->getKey())
        ->and($payment->fresh()->paid_at)->not->toBeNull();
});

it('does not start checkout when the tenant plan does not include online payments', function (): void {
    $customer = User::factory()->create();
    $booking = Booking::factory()->create(['user_id' => $customer->getKey()]);
    $service = Service::factory()->create();
    $subService = SubService::factory()->for($service)->pricedAt('125.00')->create();
    $booking->services()->attach($subService->getKey(), ['price' => '125.00']);

    $tenant = new Tenant();
    $tenant->setAttribute('id', 'tenant-payment-feature-test');

    $tenantContext = Mockery::mock(CurrentTenantContext::class);
    $tenantContext->shouldReceive('current')->once()->andReturn($tenant);
    app()->instance(CurrentTenantContext::class, $tenantContext);

    $subscriptionService = Mockery::mock(SubscriptionService::class);
    $subscriptionService->shouldReceive('hasFeature')
        ->once()
        ->with($tenant, 'online_payment')
        ->andReturnFalse();
    app()->instance(SubscriptionService::class, $subscriptionService);

    expect(fn () => app(StartBookingPayment::class)->handle($booking, $customer, PaymentProvider::PayPal))
        ->toThrow(PaymentGatewayException::class, 'غير متاح في باقة هذا المحل')
        ->and(BookingPayment::query()->count())->toBe(0);
});

it('renders the online payment action for a pending booking without a payment attempt', function (): void {
    $booking = Booking::factory()->create(['status' => 'pending']);

    $html = view('payment::components.customer.booking-payment-action', [
        'booking' => $booking,
    ])->render();

    expect($html)
        ->toContain('الدفع عبر PayPal')
        ->toContain(route('customer.bookings.payments.paypal', ['booking' => $booking->getKey()]));
});

it('renders the paid state instead of another payment button', function (): void {
    $payment = createPendingPayPalBookingPayment([
        'status' => PaymentStatus::Paid,
        'provider_transaction_id' => 'PAYPAL-CAPTURE-PAID-1',
        'paid_at' => now(),
    ]);

    $html = view('payment::components.customer.booking-payment-action', [
        'booking' => $payment->booking,
    ])->render();

    expect($html)
        ->toContain('تم الدفع')
        ->not->toContain('الدفع عبر PayPal');
});

it('captures a PayPal order from the approved-order webhook even when the customer does not return', function (): void {
    config()->set('payments.gateways.paypal', FakePaymentGateway::class);

    $payment = createPendingPayPalBookingPayment([
        'status' => PaymentStatus::Pending,
    ]);
    $event = [
        'id' => 'WH-TEST-ORDER-APPROVED-1',
        'event_type' => 'CHECKOUT.ORDER.APPROVED',
        'resource' => [
            'id' => 'PAYPAL-ORDER-TEST-1',
            'status' => 'APPROVED',
        ],
    ];

    expect(app(ProcessPayPalWebhook::class)->handle($event))->toBe('processed')
        ->and($payment->fresh()->status)->toBe(PaymentStatus::Paid)
        ->and($payment->fresh()->provider_transaction_id)->toBe('fake-transaction-'.$payment->getKey())
        ->and($payment->fresh()->metadata['capture_source'])->toBe('paypal_webhook')
        ->and(PaymentWebhookEvent::query()->where('event_id', 'WH-TEST-ORDER-APPROVED-1')->value('status'))
        ->toBe('processed')
        ->and(app(ProcessPayPalWebhook::class)->handle($event))->toBe('duplicate');
});

it('reprocesses a previously received approved-order webhook after an interrupted attempt', function (): void {
    config()->set('payments.gateways.paypal', FakePaymentGateway::class);

    $payment = createPendingPayPalBookingPayment([
        'status' => PaymentStatus::Pending,
    ]);
    $event = [
        'id' => 'WH-TEST-ORDER-APPROVED-RETRY-1',
        'event_type' => 'CHECKOUT.ORDER.APPROVED',
        'resource' => [
            'id' => 'PAYPAL-ORDER-TEST-1',
            'status' => 'APPROVED',
        ],
    ];

    PaymentWebhookEvent::query()->create([
        'provider' => PaymentProvider::PayPal->value,
        'event_id' => 'WH-TEST-ORDER-APPROVED-RETRY-1',
        'event_type' => 'CHECKOUT.ORDER.APPROVED',
        'status' => 'received',
        'payload' => $event,
    ]);

    expect(app(ProcessPayPalWebhook::class)->handle($event))->toBe('processed')
        ->and($payment->fresh()->status)->toBe(PaymentStatus::Paid);
});

it('processes a verified completed capture webhook and marks its payment as paid', function (): void {
    $payment = createPendingPayPalBookingPayment();
    $event = makePaymentCaptureWebhookEvent('WH-TEST-COMPLETED-1', 'PAYPAL-ORDER-TEST-1');

    $result = app(ProcessPayPalWebhook::class)->handle($event);

    expect($result)->toBe('processed')
        ->and($payment->fresh()->status)->toBe(PaymentStatus::Paid)
        ->and($payment->fresh()->provider_transaction_id)->toBe('PAYPAL-CAPTURE-TEST-1')
        ->and(PaymentWebhookEvent::query()->where('event_id', 'WH-TEST-COMPLETED-1')->value('status'))
        ->toBe('processed');
});

it('does not process the same PayPal webhook event twice', function (): void {
    $payment = createPendingPayPalBookingPayment();
    $event = makePaymentCaptureWebhookEvent('WH-TEST-DUPLICATE-1', 'PAYPAL-ORDER-TEST-1');
    $action = app(ProcessPayPalWebhook::class);

    expect($action->handle($event))->toBe('processed')
        ->and($action->handle($event))->toBe('duplicate')
        ->and(PaymentWebhookEvent::query()->where('event_id', 'WH-TEST-DUPLICATE-1')->count())->toBe(1)
        ->and($payment->fresh()->status)->toBe(PaymentStatus::Paid);
});

it('rejects a completed webhook when the amount does not match the stored booking payment', function (): void {
    $payment = createPendingPayPalBookingPayment();
    $event = makePaymentCaptureWebhookEvent(
        'WH-TEST-MISMATCH-1',
        'PAYPAL-ORDER-TEST-1',
        amount: '124.00',
    );

    $result = app(ProcessPayPalWebhook::class)->handle($event);

    expect($result)->toBe('rejected')
        ->and($payment->fresh()->status)->toBe(PaymentStatus::Processing)
        ->and(PaymentWebhookEvent::query()->where('event_id', 'WH-TEST-MISMATCH-1')->value('error_code'))
        ->toBe('booking_payment_result_mismatch');
});

it('keeps a pending capture in processing state', function (): void {
    $payment = createPendingPayPalBookingPayment();
    $event = makePaymentCaptureWebhookEvent(
        'WH-TEST-PENDING-1',
        'PAYPAL-ORDER-TEST-1',
        eventType: 'PAYMENT.CAPTURE.PENDING',
    );

    expect(app(ProcessPayPalWebhook::class)->handle($event))->toBe('processed')
        ->and($payment->fresh()->status)->toBe(PaymentStatus::Processing);
});

it('marks a denied capture as failed so the customer can retry payment', function (): void {
    $payment = createPendingPayPalBookingPayment();
    $event = makePaymentCaptureWebhookEvent(
        'WH-TEST-DENIED-1',
        'PAYPAL-ORDER-TEST-1',
        eventType: 'PAYMENT.CAPTURE.DENIED',
    );

    expect(app(ProcessPayPalWebhook::class)->handle($event))->toBe('processed')
        ->and($payment->fresh()->status)->toBe(PaymentStatus::Failed);
});

it('stores unrelated PayPal event types as ignored without changing a payment', function (): void {
    $payment = createPendingPayPalBookingPayment();
    $event = [
        'id' => 'WH-TEST-IGNORED-1',
        'event_type' => 'CHECKOUT.ORDER.APPROVED',
        'resource' => ['id' => 'PAYPAL-ORDER-TEST-1'],
    ];

    $result = app(ProcessPayPalWebhook::class)->handle($event);

    expect($result)->toBe('ignored')
        ->and($payment->fresh()->status)->toBe(PaymentStatus::Processing)
        ->and(PaymentWebhookEvent::query()->where('event_id', 'WH-TEST-IGNORED-1')->value('status'))
        ->toBe('ignored');
});

it('asks PayPal to retry when the related booking payment has not been saved yet', function (): void {
    $event = makePaymentCaptureWebhookEvent('WH-TEST-NOT-FOUND-1', 'MISSING-ORDER');

    expect(fn () => app(ProcessPayPalWebhook::class)->handle($event))
        ->toThrow(PaymentGatewayException::class, 'has not been found yet');

    expect(PaymentWebhookEvent::query()->where('event_id', 'WH-TEST-NOT-FOUND-1')->exists())->toBeFalse();
});

it('returns HTTP 400 when PayPal webhook signature verification fails', function (): void {
    /** @var PayPalWebhookVerifier&MockInterface $verifier */
    $verifier = Mockery::mock(PayPalWebhookVerifier::class);
    $verifier->shouldReceive('verify')->once()->andReturnFalse();

    $event = [
        'id' => 'WH-TEST-INVALID-SIGNATURE',
        'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
        'resource' => [],
    ];

    $request = Request::create(
        '/payments/paypal/webhook',
        'POST',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json'],
        json_encode($event, JSON_THROW_ON_ERROR),
    );

    $response = (new PayPalWebhookController())(
        $request,
        $verifier,
        app(ProcessPayPalWebhook::class),
    );

    expect($response->getStatusCode())->toBe(400);
});

it('processes the event through the webhook controller after signature verification', function (): void {
    $payment = createPendingPayPalBookingPayment();

    /** @var PayPalWebhookVerifier&MockInterface $verifier */
    $verifier = Mockery::mock(PayPalWebhookVerifier::class);
    $event = makePaymentCaptureWebhookEvent('WH-TEST-CONTROLLER-1', 'PAYPAL-ORDER-TEST-1');
    $rawEventBody = "{\n  \"id\": \"WH-TEST-CONTROLLER-1\",\n  \"event_type\": \"PAYMENT.CAPTURE.COMPLETED\",\n  \"resource_type\": \"capture\",\n  \"resource\": "
        .json_encode($event['resource'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)
        ."\n}";

    $verifier->shouldReceive('verify')
        ->once()
        ->with(Mockery::type('array'), $rawEventBody)
        ->andReturnTrue();

    $request = Request::create(
        '/payments/paypal/webhook',
        'POST',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json'],
        $rawEventBody,
    );

    $response = (new PayPalWebhookController())(
        $request,
        $verifier,
        app(ProcessPayPalWebhook::class),
    );

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true)['result'])->toBe('processed')
        ->and($payment->fresh()->status)->toBe(PaymentStatus::Paid);
});

it('registers the PayPal webhook on tenant routes without customer authentication', function (): void {
    $route = Route::getRoutes()->getByName('payments.paypal.webhook');

    expect($route)->not->toBeNull()
        ->and($route->methods())->toContain('POST')
        ->and($route->middleware())->not->toContain('auth:customer')
        ->and($route->middleware())->not->toContain(config('payments.routes.tenant_active_middleware'));
});
