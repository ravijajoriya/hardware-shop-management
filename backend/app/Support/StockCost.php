<?php

namespace App\Support;

/**
 * A size's purchase price kept as the average cost of the stock on hand, in paise.
 */
final class StockCost
{
    /**
     * The cost after new stock comes in: the average of the stock already there and the new
     * stock, weighted by quantity. 10 pc at ₹280 and 20 pc at ₹310 make 30 pc at ₹300.
     * With no stock (or less than none) left to average with, the new price is the cost.
     */
    public static function afterAdding(float $stock, ?int $cost, float $quantity, int $price): int
    {
        if ($stock <= 0 || $cost === null) {
            return $price;
        }

        return (int) round(($stock * $cost + $quantity * $price) / ($stock + $quantity));
    }

    /**
     * The cost after a delivery is taken back out: the average without that delivery in it.
     * When too little stock is left to tell, the cost stays as it is.
     */
    public static function afterRemoving(float $stock, ?int $cost, float $quantity, int $price): ?int
    {
        $left = $stock - $quantity;

        if ($cost === null || $left <= 0) {
            return $cost;
        }

        $value = $stock * $cost - $quantity * $price;

        return $value > 0 ? (int) round($value / $left) : $cost;
    }
}
