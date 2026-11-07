<?php

namespace Tests\Feature\Admin;

use App\Models\BillItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Admin\Concerns\MakesBills;
use Tests\TestCase;

class SalesReportTest extends TestCase
{
    use MakesBills;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::factory()->admin()->create());
        config(['shop.timezone' => 'Asia/Kolkata']);
    }

    /**
     * Two days of trade:
     * 1 Nov — Ramesh buys 2 hammers (₹700, paid in cash); Suresh buys 1.5 kg of nails (₹180, unpaid).
     * 2 Nov — Ramesh buys 1 more hammer (₹350 by UPI) and brings back one from 1 Nov (₹350 given
     *         back in cash); Suresh pays ₹100 by bank; a bill made by mistake is cancelled.
     * Hammers cost the shop ₹280, nails ₹90 a kg.
     */
    private function twoDays(): void
    {
        $hammer = $this->itemInStock('Claw Hammer', 350, stock: 20);
        $hammer->update(['purchase_price' => 280]);
        $nails = $this->itemInStock('Wire Nails', 120, stock: 20, decimals: true, size: '2 inch');
        $nails->update(['purchase_price' => 90]);

        $this->travelTo(Carbon::parse('2026-11-01 10:00', 'Asia/Kolkata'));
        $first = $this->postBill('Ramesh Kumar', '9876543210', [[$hammer, 2]], [['method' => 'cash', 'amount' => 700]])->json('data');
        $nailsBill = $this->postBill('Suresh Patel', '9123456780', [[$nails, 1.5]])->json('data');

        // Just after midnight in India, which is still 1 November in UTC.
        $this->travelTo(Carbon::parse('2026-11-02 00:30', 'Asia/Kolkata'));
        $this->postBill('Ramesh Kumar', '9876543210', [[$hammer, 1]], [['method' => 'upi', 'amount' => 350]]);
        $this->postJson("/api/admin/bills/{$first['id']}/returns", [
            'items' => [['bill_item_id' => $first['items'][0]['id'], 'quantity' => 1]],
            'refund' => ['method' => 'cash'],
        ])->assertOk();
        $this->postJson("/api/admin/bills/{$nailsBill['id']}/payments", ['method' => 'bank_transfer', 'amount' => 100])->assertOk();
        $mistake = $this->postBill('Ramesh Kumar', '9876543210', [[$hammer, 5]])->json('data');
        $this->postJson("/api/admin/bills/{$mistake['id']}/cancel", ['reason' => 'Made twice'])->assertOk();
    }

    public function test_guests_and_non_admins_cannot_see_reports(): void
    {
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/admin/reports/sales?from=2026-11-01&to=2026-11-30')->assertUnauthorized();

        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/admin/reports/sales?from=2026-11-01&to=2026-11-30')->assertForbidden();
    }

    public function test_the_report_adds_up_sales_returns_cost_profit_and_money_by_the_shops_calendar(): void
    {
        $this->twoDays();

        $this->getJson('/api/admin/reports/sales?from=2026-11-01&to=2026-11-02')
            ->assertOk()
            ->assertJsonPath('data.from', '2026-11-01')
            ->assertJsonPath('data.summary', [
                'bills' => 3,
                'sales' => 1230,
                'returns' => 350,
                'net_sales' => 880,
                // 3 hammers and 1.5 kg of nails out, 1 hammer back: 560 + 135 + 280 − 280.
                'cost' => 695,
                'profit' => 185,
                'margin' => 21,
                'cost_estimated' => false,
                'received' => ['cash' => 700, 'upi' => 350, 'bank_transfer' => 100, 'total' => 1150],
                'refunded' => ['cash' => 350, 'upi' => 0, 'bank_transfer' => 0, 'total' => 350],
                'net_received' => 800,
                'due' => ['total' => 80, 'bills' => 1, 'customers' => 1],
                'due_overall' => ['total' => 80, 'bills' => 1, 'customers' => 1],
            ])
            ->assertJsonPath('data.days', [
                [
                    'date' => '2026-11-01', 'bills' => 2, 'sales' => 880, 'returns' => 0, 'net_sales' => 880,
                    'cost' => 695, 'profit' => 185, 'margin' => 21, 'received' => 700, 'refunded' => 0,
                ],
                [
                    'date' => '2026-11-02', 'bills' => 1, 'sales' => 350, 'returns' => 350, 'net_sales' => 0,
                    'cost' => 0, 'profit' => 0, 'margin' => null, 'received' => 450, 'refunded' => 350,
                ],
            ])
            ->assertJsonPath('data.products.0', [
                'product_id' => BillItem::where('product_name', 'Claw Hammer')->value('product_id'),
                'name' => 'Claw Hammer', 'variant_name' => null, 'unit' => 'pc',
                'quantity' => 2, 'amount' => 700, 'profit' => 140,
            ])
            ->assertJsonPath('data.products.1.name', 'Wire Nails')
            ->assertJsonPath('data.products.1.quantity', 1.5)
            ->assertJsonPath('data.products.1.profit', 45)
            ->assertJsonPath('data.customers.0.name', 'Ramesh Kumar')
            ->assertJsonPath('data.customers.0.bills', 2)
            ->assertJsonPath('data.customers.0.amount', 700)
            ->assertJsonPath('data.customers.0.due', 0)
            ->assertJsonPath('data.customers.1', [
                'id' => User::where('mobile', '9123456780')->value('id'),
                'name' => 'Suresh Patel', 'bills' => 1, 'amount' => 180, 'due' => 80,
            ]);

        // A return counts on the day the items came back, whenever they were sold.
        $this->getJson('/api/admin/reports/sales?from=2026-11-02&to=2026-11-02')
            ->assertJsonPath('data.summary.sales', 350)
            ->assertJsonPath('data.summary.returns', 350)
            ->assertJsonPath('data.summary.net_sales', 0)
            // One hammer out and one back nets to nothing, so neither tops a list.
            ->assertJsonPath('data.products', [])
            ->assertJsonPath('data.customers', []);
    }

    public function test_bills_made_before_costs_were_kept_are_flagged_as_estimates(): void
    {
        $this->twoDays();
        BillItem::where('product_name', 'Wire Nails')->update(['cost_estimated' => true]);

        $this->getJson('/api/admin/reports/sales?from=2026-11-01&to=2026-11-02')
            ->assertJsonPath('data.summary.cost_estimated', true);
    }

    public function test_the_report_shows_what_is_still_due_on_its_bills_and_in_all(): void
    {
        $this->twoDays();

        // Suresh's ₹180 bill of 1 November, of which he has paid ₹100.
        $this->getJson('/api/admin/reports/sales?from=2026-11-01&to=2026-11-01')
            ->assertOk()
            ->assertJsonPath('data.summary.due', ['total' => 80, 'bills' => 1, 'customers' => 1])
            ->assertJsonPath('data.summary.due_overall', ['total' => 80, 'bills' => 1, 'customers' => 1]);

        // The bills of 2 November are paid, and the cancelled one owes nothing.
        $this->getJson('/api/admin/reports/sales?from=2026-11-02&to=2026-11-02')
            ->assertOk()
            ->assertJsonPath('data.summary.due', ['total' => 0, 'bills' => 0, 'customers' => 0])
            ->assertJsonPath('data.summary.due_overall.total', 80);
    }

    public function test_a_quiet_period_shows_zeros(): void
    {
        $this->getJson('/api/admin/reports/sales?from=2026-01-01&to=2026-01-31')
            ->assertOk()
            ->assertJsonPath('data.summary.sales', 0)
            ->assertJsonPath('data.summary.margin', null)
            ->assertJsonPath('data.days', [])
            ->assertJsonPath('data.products', [])
            ->assertJsonPath('data.customers', []);
    }

    public function test_the_period_is_checked(): void
    {
        $this->getJson('/api/admin/reports/sales?from=2026-11-10&to=2026-11-01')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['to']);

        $this->getJson('/api/admin/reports/sales?from=2024-01-01&to=2026-01-01')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['to' => 'Choose a period of a year or less.']);

        $this->getJson('/api/admin/reports/sales')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['from', 'to']);
    }
}
