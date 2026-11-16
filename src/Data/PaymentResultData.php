<?php

declare(strict_types=1);

namespace Ahmed\LaravelPayment\Data;

use Ahmed\LaravelPayment\Enums\PaymentStatus;
use InvalidArgumentException;

final readonly class PaymentResultData
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct( public PaymentStatus $status, public string $amount, public string $currency, public ?string $providerReference = null, public ?string $transactionId = null, public array $metadata = [],
    ) {
        self::assertValidAmount($this->amount);
        self::assertValidCurrency($this->currency);
    }

    private static function assertValidAmount(string $amount): void
    {
        if (! preg_match('/^\d{1,10}(?:\.\d{1,2})?$/D', $amount)) {
            throw new InvalidArgumentException('The payment result amount must be a non-negative decimal with up to two decimal places.');
        }
    }

    private static function assertValidCurrency(string $currency): void
    {
        if (! preg_match('/^[A-Z]{3}$/D', $currency)) {
            throw new InvalidArgumentException('The payment result currency must be a three-letter uppercase code.');
        }
    }
}
