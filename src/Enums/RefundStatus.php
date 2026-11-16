<?php

declare(strict_types=1);

namespace Ahmed\LaravelPayment\Enums;

enum RefundStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
}
