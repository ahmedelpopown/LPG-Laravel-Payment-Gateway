<?php

declare(strict_types=1);

namespace Ahmed\LaravelPayment\Payments;

use Ahmed\LaravelPayment\Contracts\PaymentGateway;
use Ahmed\LaravelPayment\Enums\PaymentProvider;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use LogicException;

final class PaymentGatewayManager
{
    public function __construct(
        private readonly Container $container,
    ) {}

    /**
     * Resolve the gateway configured for the requested provider.
     *
     * @throws InvalidArgumentException When the provider name is not supported.
     * @throws LogicException When the gateway is missing or misconfigured.
     */
    public function driver(PaymentProvider|string $provider): PaymentGateway
    {
        $provider = $provider instanceof PaymentProvider
            ? $provider
            : PaymentProvider::tryFrom($provider);

        if ($provider === null) {
            throw new InvalidArgumentException('The requested payment provider is not supported.');
        }

        $gatewayClass = config("payments.gateways.{$provider->value}");

        if (! is_string($gatewayClass) || $gatewayClass === '') {
            throw new LogicException("The [{$provider->value}] payment gateway is not configured.");
        }

        if (! class_exists($gatewayClass)) {
            throw new LogicException("The configured [{$provider->value}] payment gateway class does not exist.");
        }

        if (! is_subclass_of($gatewayClass, PaymentGateway::class)) {
            throw new LogicException("The configured [{$provider->value}] gateway must implement ".PaymentGateway::class.'.');
        }

        $gateway = $this->container->make($gatewayClass);

        if (! $gateway instanceof PaymentGateway) {
            throw new LogicException("Unable to resolve the [{$provider->value}] payment gateway.");
        }

        return $gateway;
    }
}
