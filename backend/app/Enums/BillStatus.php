<?php

namespace App\Enums;

enum BillStatus: string
{
    case Paid = 'paid';
    case PartlyPaid = 'partly_paid';
    case Unpaid = 'unpaid';
    case Returned = 'returned';
    case Cancelled = 'cancelled';

    /**
     * The status for a bill from its amounts, all in paise. What the customer has to pay is
     * the total less returns; what they have paid is the payments less refunds.
     * (A cancelled bill is marked so when it is cancelled, whatever its amounts.)
     */
    public static function forAmounts(int $total, int $paid, int $returned = 0, int $refunded = 0): self
    {
        $payable = $total - $returned;
        $netPaid = $paid - $refunded;

        return match (true) {
            $total > 0 && $payable <= 0 => self::Returned,
            $netPaid >= $payable => self::Paid,
            $netPaid <= 0 => self::Unpaid,
            default => self::PartlyPaid,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Paid => 'Paid',
            self::PartlyPaid => 'Partly paid',
            self::Unpaid => 'Unpaid',
            self::Returned => 'Fully returned',
            self::Cancelled => 'Cancelled',
        };
    }
}
