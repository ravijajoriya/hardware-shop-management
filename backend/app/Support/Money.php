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
     * Rupees as shown to people, with Indian digit grouping: ₹1,23,456.50.
     */
    public static function format(int $paise): string
    {
        return (new NumberFormatter('en_IN', NumberFormatter::CURRENCY))
            ->formatCurrency(self::toRupees($paise), 'INR');
    }
}
