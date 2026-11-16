<?php

declare(strict_types=1);

use Ahmed\LaravelPayment\Exceptions\PaymentGatewayException;
use Ahmed\LaravelPayment\Payments\PayPal\PayPalClient;
use Ahmed\LaravelPayment\Payments\PayPal\PayPalCredentialsResolver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

function makeConfiguredPayPalClient(): PayPalClient
{
    config()->set('payments.paypal.client_id', 'paypal-test-client-id');
    config()->set('payments.paypal.client_secret', 'paypal-test-client-secret');
    config()->set('payments.paypal.webhook_id', 'WH-TEST-PAYPAL');
    config()->set('payments.paypal.mode', 'sandbox');

    $cache = Cache::store('array');
    $cacheKey = 'payments.paypal.access_token.global.sandbox.'.hash('sha256', 'paypal-test-client-id');
    $cache->put($cacheKey, 'paypal-test-access-token', now()->addHour());

    return new PayPalClient(Http::getFacadeRoot(), $cache, new PayPalCredentialsResolver());
}

it('creates a PayPal order with the bearer token and idempotency header', function (): void {
    Http::fake([
        '*api-m.sandbox.paypal.com/v2/checkout/orders' => Http::response([
            'id' => 'ORDER-CREATE-TEST',
            'status' => 'CREATED',
        ], 201),
    ]);

    $result = makeConfiguredPayPalClient()->createOrder(
        ['intent' => 'CAPTURE'],
        'stable-create-request-id',
    );

    expect($result['id'])->toBe('ORDER-CREATE-TEST');

    Http::assertSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), '/v2/checkout/orders')
        && $request->hasHeader('Authorization')
        && $request->hasHeader('PayPal-Request-Id', 'stable-create-request-id'));
});

it('sends capture as an empty JSON object with the stable request id', function (): void {
    Http::fake([
        '*api-m.sandbox.paypal.com/v2/checkout/orders/ORDER-CAPTURE-TEST/capture' => Http::response([
            'id' => 'ORDER-CAPTURE-TEST',
            'status' => 'COMPLETED',
        ]),
    ]);

    $result = makeConfiguredPayPalClient()->captureOrder('ORDER-CAPTURE-TEST', 'stable-capture-request-id');

    expect($result['status'])->toBe('COMPLETED');

    Http::assertSent(fn (ClientRequest $request): bool => str_ends_with(
        $request->url(),
        '/v2/checkout/orders/ORDER-CAPTURE-TEST/capture',
    ) && $request->hasHeader('PayPal-Request-Id', 'stable-capture-request-id')
        && $request->body() === '{}');
});

it('retrieves an existing PayPal order without attempting another capture', function (): void {
    Http::fake([
        '*api-m.sandbox.paypal.com/v2/checkout/orders/ORDER-SHOW-TEST' => Http::response([
            'id' => 'ORDER-SHOW-TEST',
            'status' => 'COMPLETED',
        ]),
    ]);

    $result = makeConfiguredPayPalClient()->showOrder('ORDER-SHOW-TEST');

    expect($result['id'])->toBe('ORDER-SHOW-TEST')
        ->and($result['status'])->toBe('COMPLETED');

    Http::assertSent(fn (ClientRequest $request): bool => $request->method() === 'GET'
        && str_ends_with($request->url(), '/v2/checkout/orders/ORDER-SHOW-TEST'));
});

it('extracts ORDER_ALREADY_CAPTURED from the nested PayPal error details', function (): void {
    Http::fake([
        '*api-m.sandbox.paypal.com/v2/checkout/orders/ORDER-ALREADY-CAPTURED/capture' => Http::response([
            'name' => 'UNPROCESSABLE_ENTITY',
            'message' => 'The request could not be completed.',
            'details' => [[
                'issue' => 'ORDER_ALREADY_CAPTURED',
                'description' => 'Order was already captured.',
            ]],
        ], 422),
    ]);

    try {
        makeConfiguredPayPalClient()->captureOrder('ORDER-ALREADY-CAPTURED', 'stable-capture-request-id');
        test()->fail('Expected PayPal to reject a duplicate capture.');
    } catch (PaymentGatewayException $exception) {
        expect($exception->providerCode)->toBe('ORDER_ALREADY_CAPTURED')
            ->and($exception->httpStatus)->toBe(422);
    }
});

it('does not misclassify PayPal authentication HTTP errors as network failures', function (): void {
    Cache::store('array')->flush();

    Http::fake([
        '*api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response([
            'error' => 'invalid_client',
            'error_description' => 'Client authentication failed.',
        ], 401),
    ]);

    try {
        makeConfiguredPayPalClient()->checkAvailability();
        test()->fail('Expected the invalid credentials response to fail.');
    } catch (PaymentGatewayException $exception) {
        expect($exception->providerCode)->toBe('invalid_client')
            ->and($exception->providerCode)->not->toBe('paypal_authentication_connection_error')
            ->and($exception->httpStatus)->toBe(401);
    }
});

it('forces a fresh OAuth request when checking PayPal availability', function (): void {
    Http::fake([
        '*api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response([
            'access_token' => 'fresh-paypal-access-token',
            'expires_in' => 3600,
            'token_type' => 'Bearer',
        ]),
    ]);

    makeConfiguredPayPalClient()->checkAvailability();

    Http::assertSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), '/v1/oauth2/token')
        && $request->hasHeader('Authorization'));
});


it('classifies an actual OAuth network timeout as a connection error', function (): void {
    Cache::store('array')->flush();
    Http::fake(function (): never {
        throw new ConnectionException('Simulated OAuth timeout.');
    });

    try {
        makeConfiguredPayPalClient()->checkAvailability();
        test()->fail('Expected a connection error.');
    } catch (PaymentGatewayException $exception) {
        expect($exception->providerCode)->toBe('paypal_authentication_connection_error')
            ->and($exception->httpStatus)->toBeNull();
    }
});

it('classifies an actual Capture network timeout as a connection error', function (): void {
    Http::fake(function (): never {
        throw new ConnectionException('Simulated Capture timeout.');
    });

    try {
        makeConfiguredPayPalClient()->captureOrder('ORDER-NETWORK-TEST', 'stable-capture-request-id');
        test()->fail('Expected a capture connection error.');
    } catch (PaymentGatewayException $exception) {
        expect($exception->providerCode)->toBe('paypal_connection_error')
            ->and($exception->httpStatus)->toBeNull();
    }
});

it('preserves the original JSON event body when verifying a PayPal webhook', function (): void {
    $rawEventBody = "{\n  \"id\": \"WH-RAW-BODY-TEST\",\n  \"event_type\": \"PAYMENT.CAPTURE.COMPLETED\"\n}";

    Http::fake([
        '*api-m.sandbox.paypal.com/v1/notifications/verify-webhook-signature' => Http::response([
            'verification_status' => 'SUCCESS',
        ]),
    ]);

    $headers = [
        'paypal-auth-algo' => 'SHA256withRSA',
        'paypal-cert-url' => 'https://api-m.sandbox.paypal.com/v1/notifications/certs/CERT-TEST',
        'paypal-transmission-id' => 'TRANSMISSION-TEST',
        'paypal-transmission-sig' => 'SIGNATURE-TEST',
        'paypal-transmission-time' => '2026-11-16T12:00:00Z',
    ];

    expect(makeConfiguredPayPalClient()->verifyWebhookSignature($headers, $rawEventBody))->toBeTrue();

    Http::assertSent(fn (ClientRequest $request): bool => str_contains(
        $request->body(),
        ',"webhook_event":'.$rawEventBody.'}',
    ));
});
