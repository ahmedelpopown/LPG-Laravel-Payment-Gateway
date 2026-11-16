<?php

declare(strict_types=1);

namespace Ahmed\LaravelPayment\Data;

use InvalidArgumentException;

final readonly class CheckoutSessionData
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(public string $providerReference,public string $checkoutUrl,public array $metadata = [], ) {
        if (trim($this->providerReference) === '') {
            throw new InvalidArgumentException('A provider checkout reference is required.');
        }

        $urlScheme = parse_url($this->checkoutUrl, PHP_URL_SCHEME);

        if (! filter_var($this->checkoutUrl, FILTER_VALIDATE_URL)
            || ! in_array($urlScheme, ['http', 'https'], true)) {
            throw new InvalidArgumentException('The checkout URL must be a valid HTTP or HTTPS URL.');
        }
    }
}
