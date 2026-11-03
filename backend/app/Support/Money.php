<?php

namespace App\Support;

use NumberFormatter;

/**
 * Rupee amounts. Sums and comparisons are done in whole paise, so 0.1 + 0.2
 * never leaves a bill one paisa short.
 */
final class Money
{
    public static function toPaise(float|int|string|null $rupees): int
    {
        return (int) round(((float) $rupees) * 100);
    }

    public static function toRupees(int $paise): float
    {
        return $paise / 100;
    }

    /**
     * Rupees as shown to people, with Indian digit grouping. Paise appear only when there
     * are some: ₹1,500 and ₹1,23,456.50. This is display only; amounts are stored in full.
     */
    public static function format(int $paise): string
    {
        $formatter = new NumberFormatter('en_IN', NumberFormatter::CURRENCY);

        if ($paise % 100 === 0) {
            $formatter->setAttribute(NumberFormatter::FRACTION_DIGITS, 0);
        }

        return $formatter->formatCurrency(self::toRupees($paise), 'INR');
    }
}
