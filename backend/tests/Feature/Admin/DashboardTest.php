<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Admin\Concerns\MakesBills;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use MakesBills;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::factory()->admin()->create());
    }

    public function test_it_sums_up_today_by_the_shops_clock(): void
    {
        config(['shop.timezone' => 'Asia/Kolkata']);
        $item = $this->itemInStock('Cement Bag', 100);
        $item->update(['purchase_price' => 80]);

        // 11:30 pm on 2 November in India: yesterday, as far as the next test moment goes.
        $this->travelTo(Carbon::parse('2026-11-02 23:30', 'Asia/Kolkata'));
        $this->postBill('Old Customer', '9000000001', [[$item, 4]], [['method' => 'cash', 'amount' => 100]]);

        // Just after midnight on 3 November in India (still 2 November in UTC).
        $this->travelTo(Carbon::parse('2026-11-03 00:30', 'Asia/Kolkata'));
        $this->postBill('Ramesh Kumar', '9876543210', [[$item, 7]], [
            ['method' => 'cash', 'amount' => 200],
            ['method' => 'upi', 'amount' => 300],
        ]);
        $this->postBill('Suresh Patel', '9123456780', [[$item, 5]], [['method' => 'bank_transfer', 'amount' => 500]]);

        $this->getJson('/api/admin/dashboard/summary')
            ->assertOk()
            ->assertExactJson(['data' => [
                'date' => '2026-11-03',
                'bills' => 2,
                'sales' => 1200,
                'returns' => 0,
                // 12 bags bought at ₹80 and sold at ₹100.
                'profit' => 240,
                'margin' => 20,
                'cost_estimated' => false,
                'collected' => ['cash' => 200, 'upi' => 300, 'bank_transfer' => 500, 'total' => 1000],
                'refunded' => ['cash' => 0, 'upi' => 0, 'bank_transfer' => 0, 'total' => 0],
                'due' => ['total' => 500, 'customers' => 2],
            ]]);
    }

    public function test_a_quiet_day_shows_zeros(): void
    {
        $this->getJson('/api/admin/dashboard/summary')
            ->assertOk()
            ->assertJsonPath('data.bills', 0)
            ->assertJsonPath('data.sales', 0)
            ->assertJsonPath('data.collected.total', 0)
            ->assertJsonPath('data.due', ['total' => 0, 'customers' => 0]);
    }
}
