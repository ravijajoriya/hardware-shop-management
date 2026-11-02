<?php

namespace App\Enums;

/**
 * How much of a bill has been paid.
 */
enum BillStatus: string
{
    case Paid = 'paid';
    case PartlyPaid = 'partly_paid';
    case Unpaid = 'unpaid';

    /**
     * The status for a bill with this total and this much paid, both in paise.
     */
    public static function forAmounts(int $total, int $paid): self
    {
        return match (true) {
            $paid >= $total => self::Paid,
            $paid === 0 => self::Unpaid,
            default => self::PartlyPaid,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Paid => 'Paid',
            self::PartlyPaid => 'Partly paid',
            self::Unpaid => 'Unpaid',
        };
    }
}
