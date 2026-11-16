<?php

namespace Ahmed\LaravelPayment\Reconciliation\Repositories;

use Ahmed\LaravelPayment\Reconciliation\Contracts\LocalPaymentRepository;
use Ahmed\LaravelPayment\Reconciliation\DTOs\LocalPaymentSnapshot;
use Ahmed\LaravelPayment\Reconciliation\Exceptions\ReconciliationException;
use Ahmed\LaravelPayment\Reconciliation\Services\Money;
use DateTimeImmutable;
use DateTimeInterface;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

final class EloquentLocalPaymentRepository implements LocalPaymentRepository
{
    public function iterateForReconciliation(
        string $gateway,
        DateTimeInterface $from,
        DateTimeInterface $to,
        int $chunkSize = 100,
    ): iterable {
        $model = $this->modelClass();
        $columns = $this->columns($gateway);

        $query = $model::query()
            ->where($columns['gateway'], $gateway)
            ->whereBetween($columns['created_at'], [$from, $to]);

        foreach ($query->lazyById(max(1, $chunkSize), $columns['id']) as $record) {
            yield $this->snapshot($record, $gateway);
        }
    }

    public function findAllByProviderReference(string $gateway, string $providerReference): array
    {
        $model = $this->modelClass();
        $columns = $this->columns($gateway);

        return $model::query()
            ->where($columns['gateway'], $gateway)
            ->where($columns['provider_reference'], $providerReference)
            ->limit(3)
            ->get()
            ->map(fn (Model $record) => $this->snapshot($record, $gateway))
            ->all();
    }

    public function findByProviderReference(string $gateway, string $providerReference): ?LocalPaymentSnapshot
    {
        return $this->findAllByProviderReference($gateway, $providerReference)[0] ?? null;
    }

    /** @return class-string<Model> */
    private function modelClass(): string
    {
        $class = config('laravel-payment-reconciliation.local_payments.model');

        if (! is_string($class) || ! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            throw new ReconciliationException(
                'Configure local_payments.model with the Eloquent Payment model, or bind the LocalPaymentRepository contract yourself.'
            );
        }

        return $class;
    }

    /** @return array<string, string> */
    private function columns(?string $gateway = null): array
    {
        $columns = array_merge([
            'id' => 'id',
            'gateway' => 'gateway',
            'provider_reference' => 'provider_reference',
            'status' => 'status',
            'amount' => 'amount_minor',
            'amount_unit' => 'minor',
            'currency' => 'currency',
            'refunded_amount' => 'refunded_amount_minor',
            'refunded_amount_unit' => 'minor',
            'created_at' => 'created_at',
        ], (array) config('laravel-payment-reconciliation.local_payments.columns', []));

        $providerColumns = (array) config('laravel-payment-reconciliation.local_payments.provider_reference_by_gateway', []);
        if ($gateway !== null && isset($providerColumns[$gateway]) && is_string($providerColumns[$gateway])) {
            $columns['provider_reference'] = $providerColumns[$gateway];
        }

        return $columns;
    }

    private function snapshot(Model $record, string $gateway): LocalPaymentSnapshot
    {
        $columns = $this->columns($gateway);
        $amount = $record->getAttribute($columns['amount']);
        $refundedColumn = $columns['refunded_amount'] ?? null;
        $refunded = is_string($refundedColumn) && $refundedColumn !== ''
            ? $record->getAttribute($refundedColumn)
            : null;
        $currency = strtoupper((string) $record->getAttribute($columns['currency']));

        if ($amount === null || ! is_numeric($amount)) {
            throw new InvalidArgumentException('A local payment has a missing or non-numeric amount.');
        }

        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new InvalidArgumentException('A local payment is missing a valid three-letter currency code.');
        }

        $amountUnit = $columns['amount_unit'];
        $refundedUnit = $columns['refunded_amount_unit'];

        if (! in_array($amountUnit, ['minor', 'major'], true) || ! in_array($refundedUnit, ['minor', 'major'], true)) {
            throw new InvalidArgumentException('Payment amount_unit and refunded_amount_unit must be either minor or major.');
        }

        $amountMinor = $amountUnit === 'major'
            ? Money::decimalToMinor((string) $amount, $currency, (array) config('laravel-payment-reconciliation.currency_scales', []))
            : Money::integerMinor((string) $amount);

        $refundedMinor = $refunded === null
            ? null
            : ($refundedUnit === 'major'
                ? Money::decimalToMinor((string) $refunded, $currency, (array) config('laravel-payment-reconciliation.currency_scales', []))
                : Money::integerMinor((string) $refunded));

        $createdAt = $record->getAttribute($columns['created_at']);
        $status = $record->getAttribute($columns['status']);
        if ($status instanceof BackedEnum) {
            $status = $status->value;
        }

        return new LocalPaymentSnapshot(
            id: (string) $record->getAttribute($columns['id']),
            gateway: $gateway,
            providerReference: $record->getAttribute($columns['provider_reference']) !== null
                ? (string) $record->getAttribute($columns['provider_reference'])
                : null,
            status: (string) $status,
            amountMinor: $amountMinor,
            currency: $currency,
            refundedAmountMinor: $refundedMinor,
            createdAt: $createdAt instanceof DateTimeInterface
                ? DateTimeImmutable::createFromInterface($createdAt)
                : ($createdAt ? new DateTimeImmutable((string) $createdAt) : null),
        );
    }
}
