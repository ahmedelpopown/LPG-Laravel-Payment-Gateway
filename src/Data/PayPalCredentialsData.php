<?php

declare(strict_types=1);

namespace Ahmed\LaravelPayment\Data;

use InvalidArgumentException;

final readonly class PayPalCredentialsData
{
    public function __construct(
        public string $clientId,
        public string $clientSecret,
        public string $mode,
        public ?string $webhookId = null,
    ) {
        if (trim($this->clientId) === '' || trim($this->clientSecret) === '') {
            throw new InvalidArgumentException('PayPal client credentials are required.');
        }

        if (! in_array($this->mode, ['sandbox', 'live'], true)) {
            throw new InvalidArgumentException('PayPal mode must be either sandbox or live.');
        }
    }

    public function baseUrl(): string
    {
        return $this->mode === 'live'
            ? 'https://api-m.paypal.com'
            : 'https://api-m.sandbox.paypal.com';
    }
}
