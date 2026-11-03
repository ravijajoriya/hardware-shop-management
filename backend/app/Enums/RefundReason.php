<?php

namespace App\Enums;

/**
 * Why money was given back to a customer.
 */
enum RefundReason: string
{
    case Edited = 'edit';
    case Returned = 'return';
    case Cancelled = 'cancel';

    public function label(): string
    {
        return match ($this) {
            self::Edited => 'Bill edited',
            self::Returned => 'Items returned',
            self::Cancelled => 'Bill cancelled',
        };
    }
}
