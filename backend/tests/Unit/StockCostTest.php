<?php

namespace Tests\Unit;

use App\Support\StockCost;
use PHPUnit\Framework\TestCase;

class StockCostTest extends TestCase
{
    public function test_new_stock_averages_with_the_stock_already_there(): void
    {
        // 10 pc at ₹280 and 20 pc at ₹310 make 30 pc at ₹300.
        $this->assertSame(30000, StockCost::afterAdding(10, 28000, 20, 31000));
        // 1.5 kg at ₹120 and 0.5 kg at ₹100 make 2 kg at ₹115.
        $this->assertSame(11500, StockCost::afterAdding(1.5, 12000, 0.5, 10000));
        // Rounded to the paisa: 1 at ₹10 and 2 at ₹10.01 make ₹10.0067, so ₹10.01.
        $this->assertSame(1001, StockCost::afterAdding(1, 1000, 2, 1001));
    }

    public function test_with_nothing_to_average_with_the_new_price_is_the_cost(): void
    {
        $this->assertSame(31000, StockCost::afterAdding(0, 28000, 20, 31000));
        $this->assertSame(31000, StockCost::afterAdding(-3, 28000, 20, 31000));
        $this->assertSame(31000, StockCost::afterAdding(10, null, 20, 31000));
    }

    public function test_taking_a_delivery_back_out_works_it_out_of_the_average(): void
    {
        $this->assertSame(28000, StockCost::afterRemoving(30, 30000, 20, 31000));
        // 5 sold at the ₹300 average in between: what is left is valued at ₹260 each.
        $this->assertSame(26000, StockCost::afterRemoving(25, 30000, 20, 31000));
    }

    public function test_with_too_little_left_the_cost_stays(): void
    {
        $this->assertSame(30000, StockCost::afterRemoving(20, 30000, 20, 31000));
        $this->assertSame(30000, StockCost::afterRemoving(5, 30000, 20, 31000));
        $this->assertNull(StockCost::afterRemoving(30, null, 20, 31000));
    }
}
