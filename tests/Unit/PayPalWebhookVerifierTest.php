<?php

declare(strict_types=1);

use Ahmed\LaravelPayment\Payments\PayPal\PayPalClient;
use Ahmed\LaravelPayment\Payments\PayPal\PayPalWebhookVerifier;
use Mockery\MockInterface;

it('delegates PayPal webhook verification to the PayPal client', function (): void {
    $headers = [
        'paypal-auth-algo' => 'SHA256withRSA',
        'paypal-cert-url' => 'https://api-m.sandbox.paypal.com/v1/notifications/certs/CERT-123',
        'paypal-transmission-id' => 'TRANSMISSION-123',
        'paypal-transmission-sig' => 'signature',
        'paypal-transmission-time' => '2026-11-08T12:00:00Z',
    ];
    $rawEventBody = '{"id":"WH-EVENT-123", "event_type":"PAYMENT.CAPTURE.COMPLETED"}';

    /** @var PayPalClient&MockInterface $client */
    $client = Mockery::mock(PayPalClient::class);
    $client->shouldReceive('verifyWebhookSignature')
        ->once()
        ->with($headers, $rawEventBody)
        ->andReturn(true);

    expect((new PayPalWebhookVerifier($client))->verify($headers, $rawEventBody))->toBeTrue();
});

it('rejects an empty webhook event before calling PayPal', function (): void {
    /** @var PayPalClient&MockInterface $client */
    $client = Mockery::mock(PayPalClient::class);
    $client->shouldNotReceive('verifyWebhookSignature');

    expect((new PayPalWebhookVerifier($client))->verify([], ''))->toBeFalse();
});
