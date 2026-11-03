<?php

namespace App\Enums;

/**
 * Why a stock count was set by hand.
 */
enum StockCorrectionReason: string
{
    case Counted = 'counted';
    case Damaged = 'damaged';
    case CustomerReturn = 'customer_return';
    case SupplierReturn = 'supplier_return';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Counted => 'Counted again',
            self::Damaged => 'Damaged or lost',
            self::CustomerReturn => 'Returned by a customer',
            self::SupplierReturn => 'Sent back to the supplier',
            self::Other => 'Other',
        };
    }
}
