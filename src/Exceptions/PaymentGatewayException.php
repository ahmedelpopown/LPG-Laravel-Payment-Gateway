<?php

declare(strict_types=1);

namespace Ahmed\LaravelPayment\Exceptions;

use RuntimeException;
use Throwable;

class PaymentGatewayException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?string $providerCode = null,
        public readonly ?int $httpStatus = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
