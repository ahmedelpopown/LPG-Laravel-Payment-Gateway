<?php

declare(strict_types=1);

namespace Ahmed\LaravelPayment\Contracts;

use Ahmed\LaravelPayment\Data\RefundResultData;
use Illuminate\Database\Eloquent\Model;

/** Optional capability for gateways that accept a distinct provider idempotency key per refund. */
interface IdempotentRefundGateway extends PaymentGateway
{
    public function refundWithIdempotency(Model $payment, string $amount, string $idempotencyKey): RefundResultData;
}
