<?php

namespace Ahmed\LaravelPayment\Reconciliation\Services;

use InvalidArgumentException;

final class Money
{
    /** ISO-4217 currencies commonly using 0 or 3 decimal places. */
    private const ZERO_DECIMAL = [
        'BIF', 'CLP', 'DJF', 'GNF', 'ISK', 'JPY', 'KMF', 'KRW', 'PYG', 'RWF', 'UGX', 'UYI', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
    ];

    private const THREE_DECIMAL = ['BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND'];

    /** Convert a decimal major-unit string to integer minor units without floating-point arithmetic. */
    public static function decimalToMinor(string|int $value, string $currency, array $overrides = []): int
    {
        $currency = strtoupper(trim($currency));
        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new InvalidArgumentException("Invalid ISO currency code [{$currency}].");
        }

        $scale = (int) ($overrides[$currency] ?? self::scaleFor($currency));

        if ($scale < 0 || $scale > 6) {
            throw new InvalidArgumentException("Unsupported decimal scale for currency [{$currency}].");
        }

        $value = trim((string) $value);
        if (! preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', $value, $matches)) {
            throw new InvalidArgumentException("Invalid monetary value [{$value}].");
        }

        $negative = ($matches[1] ?? '') === '-';
        $whole = ltrim($matches[2], '0');
        $whole = $whole === '' ? '0' : $whole;
        $fraction = $matches[3] ?? '';

        if (strlen($fraction) > $scale) {
            $extra = substr($fraction, $scale);
            if (trim($extra, '0') !== '') {
                throw new InvalidArgumentException("Amount [{$value}] has more decimal places than currency [{$currency}] supports.");
            }
            $fraction = substr($fraction, 0, $scale);
        }

        $fraction = str_pad($fraction, $scale, '0');
        $digits = ltrim($whole . $fraction, '0');
        $digits = $digits === '' ? '0' : $digits;

        $maximumMagnitude = $negative
            ? substr((string) PHP_INT_MIN, 1)
            : (string) PHP_INT_MAX;

        if (strlen($digits) > strlen($maximumMagnitude)
            || (strlen($digits) === strlen($maximumMagnitude) && strcmp($digits, $maximumMagnitude) > 0)) {
            throw new InvalidArgumentException("Amount [{$value}] exceeds the supported integer range.");
        }

        if ($negative && $digits === substr((string) PHP_INT_MIN, 1)) {
            return PHP_INT_MIN;
        }

        $result = (int) $digits;

        return $negative ? -$result : $result;
    }


    /** Validate and normalize an already-minor-unit integer without lossy casts. */
    public static function integerMinor(string|int $value): int
    {
        $value = trim((string) $value);
        if (! preg_match('/^(-?)(\d+)$/', $value, $matches)) {
            throw new InvalidArgumentException("Invalid minor-unit integer [{$value}].");
        }

        $negative = ($matches[1] ?? '') === '-';
        $digits = ltrim($matches[2], '0');
        $digits = $digits === '' ? '0' : $digits;
        $maximumMagnitude = $negative
            ? substr((string) PHP_INT_MIN, 1)
            : (string) PHP_INT_MAX;

        if (strlen($digits) > strlen($maximumMagnitude)
            || (strlen($digits) === strlen($maximumMagnitude) && strcmp($digits, $maximumMagnitude) > 0)) {
            throw new InvalidArgumentException("Minor-unit amount [{$value}] exceeds the supported integer range.");
        }

        if ($negative && $digits === substr((string) PHP_INT_MIN, 1)) {
            return PHP_INT_MIN;
        }

        $result = (int) $digits;

        return $negative ? -$result : $result;
    }

    private static function scaleFor(string $currency): int
    {
        if (in_array($currency, self::ZERO_DECIMAL, true)) {
            return 0;
        }

        if (in_array($currency, self::THREE_DECIMAL, true)) {
            return 3;
        }

        return 2;
    }
}
