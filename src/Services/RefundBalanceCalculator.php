<?php

declare(strict_types=1);

namespace Ahmed\LaravelPayment\Services;

use Ahmed\LaravelPayment\Enums\RefundStatus;
use Ahmed\LaravelPayment\Models\BookingPayment;
use Ahmed\LaravelPayment\Models\BookingPaymentRefund;
use InvalidArgumentException;

final class RefundBalanceCalculator
{
    /** Convert a decimal amount to integer minor units to avoid float rounding. */
    public function toMinorUnits(string $amount): int
    {
        $amount = trim($amount);

        if (! preg_match('/^(\d{1,10})(?:\.(\d{1,2}))?$/D', $amount, $matches)) {
            throw new InvalidArgumentException('Invalid monetary amount.');
        }

        return ((int) $matches[1] * 100) + (int) str_pad($matches[2] ?? '', 2, '0');
    }

    public function formatMinorUnits(int $amount): string
    {
        if ($amount < 0) {
            throw new InvalidArgumentException('A monetary balance cannot be negative.');
        }

        return intdiv($amount, 100).'.'.str_pad((string) ($amount % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * Pending, processing and completed refunds reserve balance. Failed refunds do not.
     */
    public function remainingAmount(BookingPayment $payment, ?int $exceptRefundId = null): string
    {
        $payment->loadMissing('refunds');

        $reserved = $payment->refunds
            ->reject(fn (BookingPaymentRefund $refund): bool => $exceptRefundId !== null
                && (int) $refund->getKey() === $exceptRefundId)
            ->filter(fn (BookingPaymentRefund $refund): bool => in_array(
                $refund->status,
                [RefundStatus::Pending, RefundStatus::Processing, RefundStatus::Completed],
                true,
            ))
            ->sum(fn (BookingPaymentRefund $refund): int => $this->toMinorUnits((string) $refund->amount));

        $remaining = max(0, $this->toMinorUnits((string) $payment->amount) - $reserved);

        return $this->formatMinorUnits($remaining);
    }
}
