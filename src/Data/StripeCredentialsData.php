<?php

declare(strict_types=1);

namespace Ahmed\LaravelPayment\Data;

use InvalidArgumentException;

final readonly class StripeCredentialsData
{
    public function __construct(
        public string $secretKey,
        public ?string $webhookSecret = null,
    ) {
        if (trim($this->secretKey) === '') {
            throw new InvalidArgumentException('A Stripe secret key is required.');
        }
    }
}
