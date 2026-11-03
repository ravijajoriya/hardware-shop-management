<?php

namespace App\Support;

use App\Enums\BillStatus;
use App\Models\Bill;

/**
 * A bill's money, worked out in one place. What the customer owes is the total, less what
 * they returned, less what they paid and did not get back:
 * due = total − returned − (paid − refunded), never below zero.
 */
final class BillAmounts
{
    /**
     * The bill's money columns from its amounts in paise.
     *
     * @return array{total: float, paid_amount: float, returned_amount: float, refunded_amount: float, due_amount: float, status: BillStatus}
     */
    public static function columns(int $total, int $paid, int $returned = 0, int $refunded = 0): array
    {
        return [
            'total' => Money::toRupees($total),
            'paid_amount' => Money::toRupees($paid),
            'returned_amount' => Money::toRupees($returned),
            'refunded_amount' => Money::toRupees($refunded),
            'due_amount' => Money::toRupees(max($total - $returned - ($paid - $refunded), 0)),
            'status' => BillStatus::forAmounts($total, $paid, $returned, $refunded),
        ];
    }

    /**
     * The bill's amounts as they stand, in paise.
     *
     * @return array{total: int, paid: int, returned: int, refunded: int, due: int}
     */
    public static function of(Bill $bill): array
    {
        return [
            'total' => Money::toPaise($bill->total),
            'paid' => Money::toPaise($bill->paid_amount),
            'returned' => Money::toPaise($bill->returned_amount),
            'refunded' => Money::toPaise($bill->refunded_amount),
            'due' => Money::toPaise($bill->due_amount),
        ];
    }

    /**
     * How much would have to be given back if the customer now owed only $payable paise:
     * whatever they have paid (and not had back) beyond it.
     */
    public static function overpaid(Bill $bill, int $payable): int
    {
        $amounts = self::of($bill);

        return max($amounts['paid'] - $amounts['refunded'] - $payable, 0);
    }
}
