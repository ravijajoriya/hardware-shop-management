<?php

namespace App\Enums;

/**
 * How a customer paid. Money not yet paid is not a method: it is the bill's due amount (udhaar).
 */
enum PaymentMethod: string
{
    case Cash = 'cash';
    case Upi = 'upi';
    case BankTransfer = 'bank_transfer';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Cash',
            self::Upi => 'UPI',
            self::BankTransfer => 'Bank transfer',
        };
    }
}
