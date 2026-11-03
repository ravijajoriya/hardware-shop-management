<?php

namespace Tests\Feature\Admin;

use App\Models\ActivityLog;
use App\Models\Bill;
use App\Models\BillItem;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\BillPdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Admin\Concerns\MakesBills;
use Tests\TestCase;

class BillEditTest extends TestCase
{
    use MakesBills;
    use RefreshDatabase;

    private ProductVariant $hammer;

    private ProductVariant $nails;

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::factory()->admin()->create(['name' => 'Ravi']));

        $this->hammer = $this->itemInStock('Claw Hammer', 350, stock: 10);
        $this->hammer->update(['purchase_price' => 280]);
        $this->nails = $this->itemInStock('Wire Nails', 120, stock: 20, decimals: true, size: '2 inch');
    }

    /**
     * @param  array<string, mixed>  $bill  The bill as the API sent it.
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, mixed>  $extra
     */
    private function edit(array $bill, array $items, array $extra = []): TestResponse
    {
        return $this->putJson("/api/admin/bills/{$bill['id']}", [
            'customer' => $bill['customer'],
            'items' => $items,
            ...$extra,
        ]);
    }

    /**
     * @param  array<string, mixed>  $line  A line as the API sent it.
     * @return array<string, mixed>
     */
    private function line(array $line, float $quantity, ?float $price = null): array
    {
        return ['id' => $line['id'], 'product_variant_id' => $line['product_variant_id'], 'quantity' => $quantity, 'price' => $price ?? $line['price']];
    }

    public function test_guests_and_non_admins_cannot_edit_or_cancel_bills(): void
    {
        $bill = $this->postBill('Ramesh Kumar', '9876543210', [[$this->hammer, 1]])->json('data');
        $this->app['auth']->forgetGuards();

        $this->putJson("/api/admin/bills/{$bill['id']}", [])->assertUnauthorized();
        $this->postJson("/api/admin/bills/{$bill['id']}/cancel", [])->assertUnauthorized();

        Sanctum::actingAs(User::factory()->create());

        $this->putJson("/api/admin/bills/{$bill['id']}", [])->assertForbidden();
        $this->postJson("/api/admin/bills/{$bill['id']}/cancel", [])->assertForbidden();
    }

    public function test_quantities_and_prices_change_in_place_and_stock_follows(): void
    {
        $bill = $this->postBill('Ramesh Kumar', '9876543210', [[$this->hammer, 2], [$this->nails, 1.5]], [
            ['method' => 'cash', 'amount' => 500],
        ])->json('data');
        [$hammer, $nails] = $bill['items'];

        $this->edit($bill, [$this->line($hammer, 3, 340), $this->line($nails, 1)])
            ->assertOk()
            ->assertJsonPath('data.bill_number', $bill['bill_number'])
            ->assertJsonPath('data.total', 1140)
            ->assertJsonPath('data.paid_amount', 500)
            ->assertJsonPath('data.due_amount', 640)
            ->assertJsonPath('data.status.value', 'partly_paid')
            ->assertJsonPath('data.items.0.quantity', 3)
            ->assertJsonPath('data.items.0.line_total', 1020)
            ->assertJsonPath('data.edited.by', 'Ravi');

        $this->assertEquals(7, $this->hammer->fresh()->stock_quantity);
        $this->assertEquals(19, $this->nails->fresh()->stock_quantity);

        $logged = ActivityLog::latest('id')->first();
        $this->assertSame('bill.updated', $logged->action->value);
        $this->assertStringStartsWith(
            "Edited bill {$bill['bill_number']}: Claw Hammer 2 pc × ₹350 = ₹700 → 3 pc × ₹340 = ₹1,020",
            $logged->description,
        );
        $this->assertContains(['field' => 'Total', 'old' => '₹880', 'new' => '₹1,140'], $logged->changes);
    }

    public function test_lines_can_be_added_and_removed(): void
    {
        $tape = $this->itemInStock('Measuring Tape', 90, stock: 5);
        $tape->update(['purchase_price' => 60]);
        $bill = $this->postBill('Ramesh Kumar', '9876543210', [[$this->hammer, 2], [$this->nails, 1.5]])->json('data');

        $this->edit($bill, [
            $this->line($bill['items'][0], 2),
            ['product_variant_id' => $tape->id, 'quantity' => 1, 'price' => 90],
        ])
            ->assertOk()
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.items.1.product_name', 'Measuring Tape')
            ->assertJsonPath('data.total', 790);

        $this->assertEquals(20, $this->nails->fresh()->stock_quantity);
        $this->assertEquals(4, $tape->fresh()->stock_quantity);
        $this->assertEquals(60, BillItem::where('product_name', 'Measuring Tape')->value('cost_price'));
    }

    public function test_a_total_below_what_was_paid_gives_the_difference_back(): void
    {
        $bill = $this->postBill('Ramesh Kumar', '9876543210', [[$this->hammer, 2]], [['method' => 'cash', 'amount' => 700]])->json('data');
        $smaller = [$this->line($bill['items'][0], 1)];

        $this->edit($bill, $smaller)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['refund.method' => 'The new total is less than what was paid, so ₹350 must be given back. Choose how.']);
        $this->assertEquals(8, $this->hammer->fresh()->stock_quantity);

        $this->edit($bill, $smaller, ['refund' => ['method' => 'upi', 'reference' => 'UPI555']])
            ->assertOk()
            ->assertJsonPath('data.total', 350)
            ->assertJsonPath('data.paid_amount', 700)
            ->assertJsonPath('data.refunded_amount', 350)
            ->assertJsonPath('data.due_amount', 0)
            ->assertJsonPath('data.status.value', 'paid')
            ->assertJsonPath('data.refunds.0.reason', ['value' => 'edit', 'label' => 'Bill edited'])
            ->assertJsonPath('data.refunds.0.method.label', 'UPI')
            ->assertJsonPath('data.refunds.0.amount', 350)
            ->assertJsonPath('data.refunds.0.reference', 'UPI555');

        $this->assertEquals(9, $this->hammer->fresh()->stock_quantity);
        $this->assertSame(['label' => 'Refunded', 'value' => 'UPI ₹350 (UPI555)'], ActivityLog::latest('id')->first()->details[0]);
    }

    public function test_the_bill_can_move_to_another_customer(): void
    {
        $bill = $this->postBill('Ramesh Kumar', '9876543210', [[$this->hammer, 1]])->json('data');

        $this->putJson("/api/admin/bills/{$bill['id']}", [
            'customer' => ['name' => 'Suresh Patel', 'mobile' => '9123456780', 'address' => 'MI Road'],
            'items' => [$this->line($bill['items'][0], 1)],
        ])
            ->assertOk()
            ->assertJsonPath('data.customer.name', 'Suresh Patel')
            ->assertJsonPath('data.customer.address', 'MI Road');

        $suresh = User::where('mobile', '9123456780')->firstOrFail();
        $this->getJson('/api/admin/customers/lookup?mobile=9123456780')->assertJsonPath('data.total_due', 350);
        $this->getJson('/api/admin/customers/lookup?mobile=9876543210')->assertJsonPath('data.total_due', 0);
        $this->assertSame($suresh->id, $this->getJson("/api/admin/bills/{$bill['id']}")->json('data.customer.id'));
    }

    public function test_editing_only_the_items_of_an_old_bill_leaves_the_customer_as_they_are_now(): void
    {
        $bill = $this->postBill('Ramesh', '9876543210', [[$this->hammer, 1]])->json('data');
        // A later bill renames the customer.
        $this->postBill('Ramesh Kumar', '9876543210', [[$this->hammer, 1]], address: 'MG Road')->assertCreated();

        $this->edit($bill, [$this->line($bill['items'][0], 2)])
            ->assertOk()
            ->assertJsonPath('data.customer.name', 'Ramesh');

        $customer = User::where('mobile', '9876543210')->firstOrFail();
        $this->assertSame('Ramesh Kumar', $customer->name);
        $this->assertSame('MG Road', $customer->address);
    }

    public function test_once_items_are_returned_only_the_customer_can_change(): void
    {
        $bill = $this->postBill('Ramesh Kumar', '9876543210', [[$this->hammer, 2]])->json('data');
        $this->postJson("/api/admin/bills/{$bill['id']}/returns", [
            'items' => [['bill_item_id' => $bill['items'][0]['id'], 'quantity' => 1]],
        ])->assertOk();

        $this->edit($bill, [$this->line($bill['items'][0], 3)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.quantity' => 'Items on this bill have been returned, so only the customer details can be changed now.']);

        $this->getJson("/api/admin/bills/{$bill['id']}")
            ->assertJsonPath('data.can', ['edit_items' => false, 'edit' => true, 'return' => true, 'cancel' => true]);

        $this->putJson("/api/admin/bills/{$bill['id']}", [
            'customer' => [...$bill['customer'], 'name' => 'Ramesh K'],
            'items' => [$this->line($bill['items'][0], 2)],
        ])
            ->assertOk()
            ->assertJsonPath('data.customer.name', 'Ramesh K')
            ->assertJsonPath('data.due_amount', 350);
    }

    public function test_edits_are_checked(): void
    {
        $bill = $this->postBill('Ramesh Kumar', '9876543210', [[$this->hammer, 2]])->json('data');
        $other = $this->postBill('Suresh Patel', '9123456780', [[$this->nails, 1]])->json('data');

        $this->edit($bill, [
            ['id' => $other['items'][0]['id'], 'quantity' => 1, 'price' => 120],
            ['product_variant_id' => $this->hammer->id, 'quantity' => 1.5, 'price' => 350],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.id' => 'This line is not on this bill.'])
            ->assertJsonMissingValidationErrors(['items.0.product_variant_id']);

        $this->edit($bill, [$this->line($bill['items'][0], 1.5)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.quantity' => 'Piece (pc) is sold in whole numbers.']);

        $this->edit($bill, [$this->line($bill['items'][0], 2), ['product_variant_id' => $this->hammer->id, 'quantity' => 1, 'price' => 350]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items.1.product_variant_id' => 'This item is on the bill twice. Change its quantity instead.']);

        $this->assertEquals(8, $this->hammer->fresh()->stock_quantity);
    }

    public function test_cancelling_puts_stock_back_and_gives_back_what_was_paid(): void
    {
        $bill = $this->postBill('Ramesh Kumar', '9876543210', [[$this->hammer, 3]], [['method' => 'cash', 'amount' => 500]])->json('data');
        // One hammer comes back first: ₹350 off the ₹550 due.
        $this->postJson("/api/admin/bills/{$bill['id']}/returns", [
            'items' => [['bill_item_id' => $bill['items'][0]['id'], 'quantity' => 1]],
        ])->assertOk()->assertJsonPath('data.due_amount', 200);

        $this->postJson("/api/admin/bills/{$bill['id']}/cancel", ['reason' => 'Wrong customer'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['refund.method' => '₹500 was paid on this bill. Choose how it is given back.']);

        $this->postJson("/api/admin/bills/{$bill['id']}/cancel", ['reason' => 'Wrong customer', 'refund' => ['method' => 'cash']])
            ->assertOk()
            ->assertJsonPath('data.status', ['value' => 'cancelled', 'label' => 'Cancelled'])
            ->assertJsonPath('data.due_amount', 0)
            ->assertJsonPath('data.refunded_amount', 500)
            ->assertJsonPath('data.cancelled.reason', 'Wrong customer')
            ->assertJsonPath('data.cancelled.by', 'Ravi')
            ->assertJsonPath('data.can', ['edit_items' => false, 'edit' => false, 'return' => false, 'cancel' => false])
            ->assertJsonPath('data.refunds.0.reason.value', 'cancel');

        // 10 − 3 sold + 1 returned + 2 put back.
        $this->assertEquals(10, $this->hammer->fresh()->stock_quantity);
        $this->getJson('/api/admin/customers/lookup?mobile=9876543210')
            ->assertJsonPath('data.total_due', 0)
            ->assertJsonPath('data.bills_count', 1);
        $this->assertStringStartsWith("Cancelled bill {$bill['bill_number']} for Ramesh Kumar (₹1,050); ₹500 given back by Cash", ActivityLog::latest('id')->first()->description);

        // Nothing more can happen to it.
        $this->postJson("/api/admin/bills/{$bill['id']}/cancel", ['reason' => 'Again'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['bill' => 'This bill is already cancelled.']);
        $this->postJson("/api/admin/bills/{$bill['id']}/payments", ['method' => 'cash', 'amount' => 10])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['amount' => 'This bill is cancelled.']);
        $this->edit($bill, [$this->line($bill['items'][0], 1)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['bill' => 'A cancelled bill cannot be edited.']);
    }

    public function test_an_unpaid_bill_cancels_without_a_refund_and_needs_a_reason(): void
    {
        $bill = $this->postBill('Ramesh Kumar', '9876543210', [[$this->hammer, 2]])->json('data');

        $this->postJson("/api/admin/bills/{$bill['id']}/cancel", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['reason']);

        $this->postJson("/api/admin/bills/{$bill['id']}/cancel", ['reason' => 'Customer left without the items'])
            ->assertOk()
            ->assertJsonPath('data.refunded_amount', 0)
            ->assertJsonPath('data.refunds', []);

        $this->assertEquals(10, $this->hammer->fresh()->stock_quantity);
        $this->assertStringContainsString('CANCELLED', app(BillPdfService::class)->html(Bill::findOrFail($bill['id'])));
    }
}
