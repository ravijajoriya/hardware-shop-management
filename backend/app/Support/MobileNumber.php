<?php

namespace App\Support;

/**
 * Indian mobile numbers, kept in one form: 10 digits starting with 6, 7, 8 or 9.
 */
final class MobileNumber
{
    public const PATTERN = '/^[6-9]\d{9}$/';

    /**
     * The 10-digit form of a number typed any common way, so "98765 43210",
     * "+91 98765-43210" and "098765 43210" are all saved as "9876543210".
     * Anything else comes back as just its digits, for validation to reject.
     */
    public static function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $value);

        return match (true) {
            strlen($digits) === 12 && str_starts_with($digits, '91') => substr($digits, 2),
            strlen($digits) === 11 && str_starts_with($digits, '0') => substr($digits, 1),
            default => $digits,
        };
    }
}
