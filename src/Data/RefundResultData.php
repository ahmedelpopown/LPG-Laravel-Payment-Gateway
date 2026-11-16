<?php

declare(strict_types=1);

namespace Ahmed\LaravelPayment\Data;

use Ahmed\LaravelPayment\Enums\PaymentStatus;
use InvalidArgumentException;

final readonly class RefundResultData
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $providerRefundId,
        public string $amount,
        public string $currency,
        public PaymentStatus $status,
        public array $metadata = [],
    ) {
        if (trim($this->providerRefundId) === '') {
            throw new InvalidArgumentException('A provider refund reference is required.');
        }

        if (! preg_match('/^\d{1,10}(?:\.\d{1,2})?$/D', $this->amount)) {
            throw new InvalidArgumentException('The refund amount must be a non-negative decimal with up to two decimal places.');
        }

        if (! preg_match('/^[A-Z]{3}$/D', $this->currency)) {
            throw new InvalidArgumentException('The refund currency must be a three-letter uppercase code.');
        }

        if (! in_array($this->status, [PaymentStatus::Processing, PaymentStatus::PartiallyRefunded, PaymentStatus::Refunded], true)) {
            throw new InvalidArgumentException('The refund result must have a processing or refunded status.');
        }
    }
}
