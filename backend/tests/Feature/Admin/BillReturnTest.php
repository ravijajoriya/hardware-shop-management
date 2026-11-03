<?php

namespace Tests\Feature\Admin;

use App\Models\ActivityLog;
use App\Models\Bill;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\BillPdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Admin\Concerns\MakesBills;
use Tests\TestCase;

class BillReturnTest extends TestCase
{
    use MakesBills;
    use RefreshDatabase;

    private ProductVariant $hammer;

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::factory()->admin()->create(['name' => 'Ravi']));

        $this->hammer = $this->itemInStock('Claw Hammer', 350, stock: 10);
    }

    /**
     * @param  array<string, mixed>  $bill
     * @param  array<string, mixed>  $extra
     */
    private function giveBack(array $bill, float $quantity, array $extra = []): TestResponse
    {
        return $this->postJson("/api/admin/bills/{$bill['id']}/returns", [
            'items' => [['bill_item_id' => $bill['items'][0]['id'], 'quantity' => $quantity]],
            ...$extra,
        ]);
    }

    public function test_guests_and_non_admins_cannot_take_returns(): void
    {
        $bill = $this->postBill('Ramesh Kumar', '9876543210', [[$this->hammer, 1]])->json('data');
        $this->app['auth']->forgetGuards();

        $this->giveBack($bill, 1)->assertUnauthorized();

        Sanctum::actingAs(User::factory()->create());

        $this->giveBack($bill, 1)->assertForbidden();
    }

    public function test_a_return_comes_off_the_due_first_and_the_rest_is_given_back(): void
    {
        $year = now(config('shop.timezone'))->year;
        // ₹1,050 bill, ₹600 paid, ₹450 due.
        $bill = $this->postBill('Ramesh Kumar', '9876543210', [[$this->hammer, 3]], [['method' => 'cash', 'amount' => 600]])->json('data');

        $this->giveBack($bill, 1, ['note' => 'Handle loose'])
            ->assertOk()
            ->assertJsonPath('return_number', "RET-{$year}-0001")
            ->assertJsonPath('data.returned_amount', 350)
            ->assertJsonPath('data.net_total', 700)
            ->assertJsonPath('data.due_amount', 100)
            ->assertJsonPath('data.refunded_amount', 0)
            ->assertJsonPath('data.status.value', 'partly_paid')
            ->assertJsonPath('data.items.0.returned_quantity', 1)
            ->assertJsonPath('data.returns.0.return_number', "RET-{$year}-0001")
            ->assertJsonPath('data.returns.0.due_reduced', 350)
            ->assertJsonPath('data.returns.0.refund_amount', 0)
            ->assertJsonPath('data.returns.0.note', 'Handle loose')
            ->assertJsonPath('data.returns.0.items.0.quantity', 1);
        $this->assertEquals(8, $this->hammer->fresh()->stock_quantity);

        // The next one is worth more than the ₹100 still due, so ₹250 has to go back.
        $this->giveBack($bill, 1)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['refund.method' => '₹250 has to be given back. Choose how.']);

        $this->giveBack($bill, 1, ['refund' => ['method' => 'cash']])
            ->assertOk()
            ->assertJsonPath('return_number', "RET-{$year}-0002")
            ->assertJsonPath('data.due_amount', 0)
            ->assertJsonPath('data.refunded_amount', 250)
            ->assertJsonPath('data.status.value', 'paid')
            ->assertJsonPath('data.returns.1.due_reduced', 100)
            ->assertJsonPath('data.returns.1.refund_amount', 250)
            ->assertJsonPath('data.returns.1.refund.method.label', 'Cash')
            ->assertJsonPath('data.refunds.0.reason', ['value' => 'return', 'label' => 'Items returned']);

        $this->assertSame(
            "Took back items worth ₹350 on bill {$bill['bill_number']} (RET-{$year}-0002): ₹100 off the due, ₹250 given back by Cash",
            ActivityLog::latest('id')->first()->description,
        );

        // The last one is all refund, and nothing is left on the bill.
        $this->giveBack($bill, 1, ['refund' => ['method' => 'upi', 'reference' => 'UPI42']])
            ->assertOk()
            ->assertJsonPath('data.net_total', 0)
            ->assertJsonPath('data.refunded_amount', 600)
            ->assertJsonPath('data.status', ['value' => 'returned', 'label' => 'Fully returned'])
            ->assertJsonPath('data.can.return', false)
            ->assertJsonPath('data.can.cancel', false);

        $this->assertEquals(10, $this->hammer->fresh()->stock_quantity);
        $this->assertStringContainsString("RET-{$year}-0003", app(BillPdfService::class)->html(Bill::findOrFail($bill['id'])));

        // With everything back, there is nothing left to cancel.
        $this->postJson("/api/admin/bills/{$bill['id']}/cancel", ['reason' => 'Mistake'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['bill' => 'Everything on this bill has already been returned, so there is nothing left to cancel.']);
    }

    public function test_only_what_is_left_can_be_returned(): void
    {
        $bill = $this->postBill('Ramesh Kumar', '9876543210', [[$this->hammer, 3]])->json('data');

        $this->giveBack($bill, 4)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.quantity' => 'Only 3 pc of this is left to return.']);

        $this->giveBack($bill, 1.5)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.quantity' => 'Piece (pc) is counted in whole numbers.']);

        $this->giveBack($bill, 3)->assertOk();

        $this->giveBack($bill, 1)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.quantity' => 'All of this has already been returned.']);
    }

    public function test_returns_are_checked(): void
    {
        $bill = $this->postBill('Ramesh Kumar', '9876543210', [[$this->hammer, 3]])->json('data');
        $other = $this->postBill('Suresh Patel', '9123456780', [[$this->hammer, 1]])->json('data');

        $this->postJson("/api/admin/bills/{$bill['id']}/returns", ['items' => []])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items' => 'Choose at least one item that is coming back.']);

        $this->postJson("/api/admin/bills/{$bill['id']}/returns", [
            'items' => [['bill_item_id' => $other['items'][0]['id'], 'quantity' => 1]],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.bill_item_id' => 'This item is not on this bill.']);

        $this->postJson("/api/admin/bills/{$bill['id']}/cancel", ['reason' => 'Mistake'])->assertOk();

        $this->giveBack($bill, 1)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items' => 'This bill is cancelled, so nothing can be returned from it.']);
    }
}
