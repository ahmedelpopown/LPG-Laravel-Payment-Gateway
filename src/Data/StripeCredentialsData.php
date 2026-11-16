<?php

declare(strict_types=1);

namespace Ahmed\LaravelPayment\Data;

use InvalidArgumentException;

final readonly class StripeCredentialsData
{
    public function __construct(
        public string $tenantId,
        public string $secretKey,
        public ?string $webhookSecret = null,
    ) {
        if (trim($this->tenantId) === '') {
            throw new InvalidArgumentException('A tenant ID is required for Stripe credentials.');
        }

        if (trim($this->secretKey) === '') {
            throw new InvalidArgumentException('A Stripe secret key is required.');
        }
    }
}
