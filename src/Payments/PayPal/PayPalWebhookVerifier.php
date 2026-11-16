<?php

declare(strict_types=1);

namespace Ahmed\LaravelPayment\Payments\PayPal;

final class PayPalWebhookVerifier
{
    public function __construct(
        private readonly PayPalClient $client,
    ) {}

    /**
     * @param array<string, string> $headers Lowercase PayPal header names.
     * @param string $rawEventBody The untouched request body sent by PayPal.
     */
    public function verify(array $headers, string $rawEventBody): bool
    {
        if (trim($rawEventBody) === '') {
            return false;
        }

        return $this->client->verifyWebhookSignature($headers, $rawEventBody);
    }
}
