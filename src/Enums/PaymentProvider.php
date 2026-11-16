<?php

declare(strict_types=1);

namespace Ahmed\LaravelPayment\Enums;

enum PaymentProvider: string
{
    case PayPal = 'paypal';
    case Stripe = 'stripe';
}
