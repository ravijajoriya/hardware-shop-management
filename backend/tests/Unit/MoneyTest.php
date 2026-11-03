<?php

namespace Tests\Unit;

use App\Support\Money;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_whole_rupees_are_shown_without_paise(): void
    {
        $this->assertSame('₹1,500', Money::format(150000));
        $this->assertSame('₹1,23,456', Money::format(12345600));
        $this->assertSame('₹0', Money::format(0));
        $this->assertSame('-₹350', Money::format(-35000));
    }

    public function test_paise_are_shown_when_there_are_some(): void
    {
        $this->assertSame('₹320.50', Money::format(32050));
        $this->assertSame('₹1,23,456.78', Money::format(12345678));
        $this->assertSame('₹0.05', Money::format(5));
    }
}
