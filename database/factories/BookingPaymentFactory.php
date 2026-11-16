<?php

declare(strict_types=1);

namespace Ahmed\LaravelPayment\Database\Factories;

use Ahmed\LaravelPayment\Enums\PaymentProvider;
use Ahmed\LaravelPayment\Enums\PaymentStatus;
use Ahmed\LaravelPayment\Models\BookingPayment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<BookingPayment>
 */
class BookingPaymentFactory extends Factory
{
    protected $model = BookingPayment::class;

    public function definition(): array
    {
        $bookingModel = config('payments.booking_model');

        return [
            'booking_id' => $bookingModel::factory(),
            'provider' => fake()->randomElement(PaymentProvider::cases()),
            'provider_reference' => null,
            'provider_transaction_id' => null,
            'idempotency_key' => Str::uuid()->toString(),
            'amount' => number_format(fake()->randomFloat(2, 10, 1000), 2, '.', ''),
            'currency' => 'EGP',
            'status' => PaymentStatus::Pending,
            'checkout_url' => null,
            'metadata' => null,
            'paid_at' => null,
            'refunded_at' => null,
        ];
    }

    public function paid(): static
    {
        return $this->state(fn (): array => [
            'status' => PaymentStatus::Paid,
            'paid_at' => now(),
        ]);
    }
}
