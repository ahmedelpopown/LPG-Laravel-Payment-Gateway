<?php

namespace Ahmed\LaravelPayment\Reconciliation\Services;

use Ahmed\LaravelPayment\Reconciliation\Contracts\ReconciliationGatewayDriver;
use Ahmed\LaravelPayment\Reconciliation\Exceptions\ReconciliationException;

final class GatewayDriverRegistry
{
    /** @var array<string, ReconciliationGatewayDriver> */
    private array $drivers = [];

    /** @param iterable<ReconciliationGatewayDriver> $drivers */
    public function __construct(iterable $drivers = [])
    {
        foreach ($drivers as $driver) {
            $this->add($driver);
        }
    }

    public function add(ReconciliationGatewayDriver $driver): void
    {
        $this->drivers[strtolower($driver->key())] = $driver;
    }

    public function get(string $gateway): ReconciliationGatewayDriver
    {
        $driver = $this->drivers[strtolower($gateway)] ?? null;

        if (! $driver) {
            throw new ReconciliationException("No reconciliation driver is registered for gateway [{$gateway}].");
        }

        return $driver;
    }
}
