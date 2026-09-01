<?php

namespace App\Support;

use NumberFormatter;

/**
 * Amounts live in minor units everywhere — paise, cents — because that is what
 * every gateway sends and expects. Formatting is the only place they become
 * human-readable, and it happens here so the app cannot drift.
 */
class Money
{
    /** Currencies with no minor unit at all; dividing these by 100 would be wrong. */
    private const ZERO_DECIMAL = ['JPY', 'KRW', 'VND', 'CLP', 'ISK', 'UGX', 'XAF', 'XOF'];

    public static function minorUnitDivisor(string $currency): int
    {
        return in_array(strtoupper($currency), self::ZERO_DECIMAL, true) ? 1 : 100;
    }

    public static function toDecimal(int $minor, string $currency): float
    {
        return $minor / self::minorUnitDivisor($currency);
    }

    public static function toMinor(float $decimal, string $currency): int
    {
        return (int) round($decimal * self::minorUnitDivisor($currency));
    }

    public static function format(int $minor, string $currency): string
    {
        $currency = strtoupper($currency);
        $amount = self::toDecimal($minor, $currency);

        // Indian grouping (1,50,000) differs from Western (150,000), and donors
        // notice. NumberFormatter handles both from the locale.
        $locale = $currency === 'INR' ? 'en_IN' : 'en_US';

        if (class_exists(NumberFormatter::class)) {
            $formatter = new NumberFormatter($locale, NumberFormatter::CURRENCY);

            return $formatter->formatCurrency($amount, $currency);
        }

        $symbols = ['INR' => '₹', 'USD' => '$', 'EUR' => '€', 'GBP' => '£'];
        $decimals = self::minorUnitDivisor($currency) === 1 ? 0 : 2;

        return ($symbols[$currency] ?? $currency.' ').number_format($amount, $decimals);
    }
}
