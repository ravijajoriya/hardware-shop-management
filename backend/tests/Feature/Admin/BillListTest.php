<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Admin\Concerns\MakesBills;
use Tests\TestCase;

class BillListTest extends TestCase
{
    use MakesBills;
    use RefreshDatabase;

    /**
     * Bill ids by a short name.
     *
     * @var array<string, int>
     */
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::factory()->admin()->create());
        config(['shop.timezone' => 'Asia/Kolkata']);

        $this->fiveBills();
    }

    /**
     * Five bills, by the shop's clock:
     * old       1 Aug 10:00 — Ramesh, 2 hammers ₹700, ₹200 cash: ₹500 due.
     * nails     1 Oct 10:00 — Suresh, 1.5 kg of nails ₹180, ₹100 by UPI: ₹80 due. Edited later.
     * midnight  2 Nov 00:30 — Mahesh, 1 hammer ₹350, unpaid. Still 1 November in UTC.
     * returned  3 Nov 12:00 — Ramesh, 2 hammers ₹700 by bank; one comes back, ₹350 given back in cash.
     * cancelled 3 Nov 13:00 — Suresh, 1 hammer ₹350 in cash; cancelled.
     * Today is 5 November.
     */
    private function fiveBills(): void
    {
        $hammer = $this->itemInStock('Claw Hammer', 350, stock: 20);
        $hammer->update(['sku' => 'HAM-01']);
        $nails = $this->itemInStock('Wire Nails', 120, stock: 20, decimals: true, size: '2 inch');

        $this->travelTo(Carbon::parse('2026-08-01 10:00', 'Asia/Kolkata'));
        $this->ids['old'] = $this->postBill('Ramesh Kumar', '9876543210', [[$hammer, 2]], [['method' => 'cash', 'amount' => 200]])->json('data.id');

        $this->travelTo(Carbon::parse('2026-10-01 10:00', 'Asia/Kolkata'));
        $bill = $this->postBill('Suresh Patel', '9123456780', [[$nails, 1.5]], [['method' => 'upi', 'amount' => 100]])->json('data');
        $this->ids['nails'] = $bill['id'];

        $this->travelTo(Carbon::parse('2026-11-02 00:30', 'Asia/Kolkata'));
        $this->ids['midnight'] = $this->postBill('Mahesh Rao', '9988776655', [[$hammer, 1]])->json('data.id');

        $this->travelTo(Carbon::parse('2026-11-03 12:00', 'Asia/Kolkata'));
        $returned = $this->postBill('Ramesh Kumar', '9876543210', [[$hammer, 2]], [['method' => 'bank_transfer', 'amount' => 700]])->json('data');
        $this->ids['returned'] = $returned['id'];
        $this->postJson("/api/admin/bills/{$returned['id']}/returns", [
            'items' => [['bill_item_id' => $returned['items'][0]['id'], 'quantity' => 1]],
            'refund' => ['method' => 'cash'],
        ])->assertOk();

        $this->travelTo(Carbon::parse('2026-11-03 13:00', 'Asia/Kolkata'));
        $this->ids['cancelled'] = $this->postBill('Suresh Patel', '9123456780', [[$hammer, 1]], [['method' => 'cash', 'amount' => 350]])->json('data.id');
        $this->postJson("/api/admin/bills/{$this->ids['cancelled']}/cancel", [
            'reason' => 'Made twice',
            'refund' => ['method' => 'cash'],
        ])->assertOk();

        $this->travelTo(Carbon::parse('2026-11-05 10:00', 'Asia/Kolkata'));
        $line = $bill['items'][0];
        $this->putJson("/api/admin/bills/{$bill['id']}", [
            'customer' => [...$bill['customer'], 'address' => 'Station Road'],
            'items' => [['id' => $line['id'], 'product_variant_id' => $line['product_variant_id'], 'quantity' => 1.5, 'price' => 120]],
        ])->assertOk();
    }

    /**
     * The short names of the bills the list shows for the query, in order.
     *
     * @return list<string>
     */
    private function found(string $query): array
    {
        $names = array_flip($this->ids);

        return collect($this->getJson("/api/admin/bills?{$query}")->assertOk()->json('data'))
            ->map(fn (array $bill) => $names[$bill['id']])
            ->all();
    }

    public function test_bills_can_be_found_by_days_of_the_shops_calendar(): void
    {
        $this->assertSame(['cancelled', 'returned', 'midnight'], $this->found('from=2026-11-02&to=2026-11-03'));
        // Made at 00:30 on 2 November in India, which is 1 November in UTC.
        $this->assertSame([], $this->found('from=2026-11-01&to=2026-11-01'));
        $this->assertSame(['cancelled', 'returned'], $this->found('from=2026-11-03'));
        $this->assertSame(['nails', 'old'], $this->found('to=2026-10-31'));
    }

    public function test_bills_can_be_found_by_payment_method_product_old_dues_and_changes(): void
    {
        // Money given back is not a payment, so the cash refund on "returned" does not count.
        $this->assertSame(['cancelled', 'old'], $this->found('method=cash'));
        $this->assertSame(['nails'], $this->found('method=upi'));
        $this->assertSame(['returned'], $this->found('method=bank_transfer'));

        $this->assertSame(['nails'], $this->found('product=nails'));
        $this->assertSame(['nails'], $this->found('product=2+inch'));
        $this->assertSame(['cancelled', 'returned', 'midnight', 'old'], $this->found('product=HAM-01'));

        $this->assertSame(['nails', 'old'], $this->found('overdue=30'));
        $this->assertSame(['old'], $this->found('overdue=90'));

        $this->assertSame(['nails'], $this->found('changes=edited'));
        $this->assertSame(['returned'], $this->found('changes=returned'));

        $this->assertSame(['old'], $this->found('search=ramesh&overdue=30'));
    }

    public function test_bills_can_be_sorted(): void
    {
        $this->assertSame(['cancelled', 'returned', 'midnight', 'nails', 'old'], $this->found(''));
        $this->assertSame(['old', 'nails', 'midnight', 'returned', 'cancelled'], $this->found('sort=oldest'));
        // Equal totals: the newer bill first.
        $this->assertSame(['returned', 'old', 'cancelled', 'midnight', 'nails'], $this->found('sort=total'));
        // Equal dues: the one owed longest first.
        $this->assertSame(['old', 'midnight', 'nails', 'returned', 'cancelled'], $this->found('sort=due'));
    }

    public function test_the_list_adds_up_all_the_matching_bills(): void
    {
        // Billed after returns: 700 + 180 + 350 + (700 − 350); the cancelled bill is not added in.
        $this->getJson('/api/admin/bills?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('totals', ['bills' => 5, 'cancelled' => 1, 'billed' => 1580, 'returned' => 350, 'due' => 930]);

        $this->getJson('/api/admin/bills?from=2026-11-03')
            ->assertJsonPath('totals', ['bills' => 2, 'cancelled' => 1, 'billed' => 350, 'returned' => 350, 'due' => 0]);

        $this->getJson('/api/admin/bills?status=cancelled')
            ->assertJsonPath('totals', ['bills' => 1, 'cancelled' => 1, 'billed' => 0, 'returned' => 0, 'due' => 0]);

        $this->getJson('/api/admin/bills?search=nobody')
            ->assertJsonPath('totals', ['bills' => 0, 'cancelled' => 0, 'billed' => 0, 'returned' => 0, 'due' => 0]);
    }

    public function test_the_filters_are_checked(): void
    {
        $this->getJson('/api/admin/bills?from=2026-11-05&to=2026-11-01')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['to']);

        $this->getJson('/api/admin/bills?from=5-11-2026&method=cheque&overdue=45&changes=lost&sort=cheapest')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['from', 'method', 'overdue', 'changes', 'sort']);
    }
}
