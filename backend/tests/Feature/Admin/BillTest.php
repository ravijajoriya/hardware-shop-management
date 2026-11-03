<?php

namespace Tests\Feature\Admin;

use App\Models\Bill;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Admin\Concerns\MakesBills;
use Tests\TestCase;

class BillTest extends TestCase
{
    use MakesBills;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        Sanctum::actingAs($this->admin);
    }

    private function year(): int
    {
        return now(config('shop.timezone'))->year;
    }

    public function test_guests_and_non_admins_cannot_use_bills(): void
    {
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/admin/bills')->assertUnauthorized();
        $this->postJson('/api/admin/bills', [])->assertUnauthorized();
        $this->getJson('/api/admin/bills/1')->assertUnauthorized();

        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/admin/bills')->assertForbidden();
        $this->postJson('/api/admin/bills', [])->assertForbidden();
    }

    public function test_a_bill_adds_a_new_customer_copies_the_items_and_reduces_stock(): void
    {
        $hammer = $this->itemInStock('Claw Hammer', 260, stock: 10);
        $nails = $this->itemInStock('Wire Nails', 120, stock: 25, decimals: true, size: '2 inch');

        $this->postBill('Ramesh Kumar', '+91 98765-43210', [[$hammer, 2], [$nails, 1.5]], [
            ['method' => 'cash', 'amount' => 700],
        ])
            ->assertCreated()
            ->assertJsonPath('data.bill_number', "INV-{$this->year()}-0001")
            ->assertJsonPath('data.customer.name', 'Ramesh Kumar')
            ->assertJsonPath('data.customer.mobile', '9876543210')
            ->assertJsonPath('data.total', 700)
            ->assertJsonPath('data.paid_amount', 700)
            ->assertJsonPath('data.due_amount', 0)
            ->assertJsonPath('data.status', ['value' => 'paid', 'label' => 'Paid'])
            ->assertJsonPath('data.items.0.product_name', 'Claw Hammer')
            ->assertJsonPath('data.items.0.variant_name', null)
            ->assertJsonPath('data.items.0.unit', 'pc')
            ->assertJsonPath('data.items.0.quantity', 2)
            ->assertJsonPath('data.items.0.line_total', 520)
            ->assertJsonPath('data.items.1.variant_name', '2 inch')
            ->assertJsonPath('data.items.1.quantity', 1.5)
            ->assertJsonPath('data.items.1.line_total', 180)
            ->assertJsonPath('data.payments.0.method', ['value' => 'cash', 'label' => 'Cash'])
            ->assertJsonPath('data.payments.0.amount', 700);

        $customer = User::where('mobile', '9876543210')->firstOrFail();
        $this->assertSame('Ramesh Kumar', $customer->name);
        $this->assertFalse($customer->is_admin);
        $this->assertNull($customer->email);
        $this->assertNull($customer->password);

        $this->assertEquals(8, $hammer->fresh()->stock_quantity);
        $this->assertEquals(23.5, $nails->fresh()->stock_quantity);
    }

    public function test_split_payments_and_unpaid_amounts_are_recorded(): void
    {
        $hammer = $this->itemInStock('Claw Hammer', 350);

        // Part cash, part UPI, the rest due.
        $this->postBill('Ramesh Kumar', '9876543210', [[$hammer, 2]], [
            ['method' => 'cash', 'amount' => 200],
            ['method' => 'upi', 'amount' => 300, 'reference' => 'UPI123456'],
        ])
            ->assertCreated()
            ->assertJsonPath('data.total', 700)
            ->assertJsonPath('data.paid_amount', 500)
            ->assertJsonPath('data.due_amount', 200)
            ->assertJsonPath('data.status.value', 'partly_paid')
            ->assertJsonPath('data.payments.1.method.label', 'UPI')
            ->assertJsonPath('data.payments.1.reference', 'UPI123456');

        // Nothing paid now: the whole bill is due.
        $this->postBill('Ramesh Kumar', '9876543210', [[$hammer, 1]])
            ->assertCreated()
            ->assertJsonPath('data.paid_amount', 0)
            ->assertJsonPath('data.due_amount', 350)
            ->assertJsonPath('data.status', ['value' => 'unpaid', 'label' => 'Unpaid'])
            ->assertJsonPath('data.payments', []);

        // Paid in full by bank transfer.
        $this->postBill('Ramesh Kumar', '9876543210', [[$hammer, 1]], [
            ['method' => 'bank_transfer', 'amount' => 350],
        ])
            ->assertCreated()
            ->assertJsonPath('data.status.value', 'paid')
            ->assertJsonPath('data.payments.0.method.label', 'Bank transfer');
    }

    public function test_payments_cannot_add_up_to_more_than_the_bill(): void
    {
        $hammer = $this->itemInStock('Claw Hammer', 260, stock: 10);

        $this->postBill('Ramesh Kumar', '9876543210', [[$hammer, 1]], [
            ['method' => 'cash', 'amount' => 200],
            ['method' => 'upi', 'amount' => 100],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['payments' => 'The payments add up to ₹300, more than the bill total of ₹260.']);

        $this->assertDatabaseCount('bills', 0);
        $this->assertDatabaseMissing('users', ['mobile' => '9876543210']);
        $this->assertEquals(10, $hammer->fresh()->stock_quantity);
    }

    public function test_a_known_mobile_number_reuses_the_customer_and_takes_the_new_name(): void
    {
        $customer = User::factory()->customer()->create(['name' => 'Ramesh', 'mobile' => '9876543210']);
        $hammer = $this->itemInStock();

        $this->postBill('Ramesh Kumar', '98765 43210', [[$hammer, 1]])
            ->assertCreated()
            ->assertJsonPath('data.customer.id', $customer->id)
            ->assertJsonPath('data.customer.name', 'Ramesh Kumar');

        $this->assertSame('Ramesh Kumar', $customer->fresh()->name);
        $this->assertSame(1, User::customers()->count());
    }

    public function test_the_address_is_saved_with_the_customer_and_printed_on_the_bill(): void
    {
        $hammer = $this->itemInStock();

        $this->postBill('Ramesh Kumar', '9876543210', [[$hammer, 1]], address: '  12, Station Road, Jaipur ')
            ->assertCreated()
            ->assertJsonPath('data.customer.address', '12, Station Road, Jaipur');

        $customer = User::where('mobile', '9876543210')->firstOrFail();
        $this->assertSame('12, Station Road, Jaipur', $customer->address);

        $this->getJson('/api/admin/customers/lookup?mobile=9876543210')
            ->assertJsonPath('data.address', '12, Station Road, Jaipur');
    }

    public function test_a_new_address_replaces_the_saved_one_but_a_blank_one_keeps_it(): void
    {
        $customer = User::factory()->customer()->create([
            'name' => 'Ramesh Kumar',
            'mobile' => '9876543210',
            'address' => '12, Station Road, Jaipur',
        ]);
        $hammer = $this->itemInStock();

        // Left blank: the saved address stays, and this bill has none.
        $first = $this->postBill('Ramesh Kumar', '9876543210', [[$hammer, 1]], address: '')
            ->assertCreated()
            ->assertJsonPath('data.customer.address', null)
            ->json('data.id');
        $this->assertSame('12, Station Road, Jaipur', $customer->fresh()->address);

        // A new address replaces the saved one; the earlier bill keeps what it had.
        $this->postBill('Ramesh Kumar', '9876543210', [[$hammer, 1]], address: '4, MI Road, Jaipur')
            ->assertCreated()
            ->assertJsonPath('data.customer.address', '4, MI Road, Jaipur');
        $this->assertSame('4, MI Road, Jaipur', $customer->fresh()->address);
        $this->getJson("/api/admin/bills/{$first}")->assertJsonPath('data.customer.address', null);
    }

    public function test_the_price_can_be_changed_on_the_bill(): void
    {
        $hammer = $this->itemInStock('Claw Hammer', 260);

        $this->postBill('Ramesh Kumar', '9876543210', [[$hammer, 2, 240]])
            ->assertCreated()
            ->assertJsonPath('data.items.0.price', 240)
            ->assertJsonPath('data.items.0.list_price', 260)
            ->assertJsonPath('data.items.0.line_total', 480)
            ->assertJsonPath('data.total', 480);
    }

    public function test_bills_are_numbered_in_order_and_start_again_each_year(): void
    {
        $hammer = $this->itemInStock();
        $year = $this->year();

        $this->postBill('A', '9876543210', [[$hammer, 1]])->assertJsonPath('data.bill_number', "INV-{$year}-0001");
        $this->postBill('B', '9876543211', [[$hammer, 1]])->assertJsonPath('data.bill_number', "INV-{$year}-0002");

        $this->travelTo(now(config('shop.timezone'))->addYear()->startOfYear()->addMinutes(5));

        $this->postBill('C', '9876543212', [[$hammer, 1]])->assertJsonPath('data.bill_number', 'INV-'.($year + 1).'-0001');
    }

    public function test_items_sold_in_whole_units_cannot_be_billed_in_fractions(): void
    {
        $hammer = $this->itemInStock('Claw Hammer');

        $this->postBill('Ramesh Kumar', '9876543210', [[$hammer, 1.5]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.quantity' => 'Piece (pc) is sold in whole numbers.']);

        $this->assertDatabaseCount('bills', 0);
    }

    public function test_selling_more_than_the_stock_shows_is_allowed(): void
    {
        $hammer = $this->itemInStock('Claw Hammer', stock: 10);

        $this->postBill('Ramesh Kumar', '9876543210', [[$hammer, 12]])->assertCreated();

        $this->assertEquals(-2, $hammer->fresh()->stock_quantity);
    }

    public function test_bills_are_validated(): void
    {
        $hammer = $this->itemInStock();

        $this->postJson('/api/admin/bills', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['customer', 'items']);

        $this->postJson('/api/admin/bills', [
            'customer' => ['name' => '', 'mobile' => '12345', 'address' => str_repeat('a', 51)],
            'items' => [
                ['product_variant_id' => $hammer->id, 'quantity' => 0, 'price' => -1],
                ['product_variant_id' => $hammer->id, 'quantity' => 1, 'price' => 260],
                ['product_variant_id' => 999, 'quantity' => 1, 'price' => 10],
            ],
            'payments' => [['method' => 'cheque', 'amount' => 0]],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'customer.name',
                'customer.mobile' => 'Enter a 10-digit mobile number, such as 98765 43210.',
                'customer.address' => 'The address field must not be greater than 50 characters.',
                'items.0.quantity',
                'items.0.price',
                'items.1.product_variant_id' => 'This item is on the bill twice. Change its quantity instead.',
                'items.2.product_variant_id' => 'This item no longer exists. Remove it from the bill.',
                'payments.0.method',
                'payments.0.amount',
            ]);

        $this->assertDatabaseCount('bills', 0);
    }

    public function test_bills_can_be_listed_searched_and_filtered(): void
    {
        $hammer = $this->itemInStock('Claw Hammer', 100);
        $year = $this->year();

        $this->postBill('Ramesh Kumar', '9876543210', [[$hammer, 1]], [['method' => 'cash', 'amount' => 100]]);
        $this->travel(1)->minutes();
        $this->postBill('Suresh Patel', '9123456780', [[$hammer, 2]]);
        $this->travel(1)->minutes();
        $this->postBill('Mahesh Rao', '9988776655', [[$hammer, 3]], [['method' => 'upi', 'amount' => 100]]);

        $numbers = fn (string $query) => collect($this->getJson("/api/admin/bills?{$query}")->assertOk()->json('data'))
            ->pluck('bill_number')
            ->all();

        $this->assertSame(["INV-{$year}-0003", "INV-{$year}-0002", "INV-{$year}-0001"], $numbers(''));
        $this->assertSame(["INV-{$year}-0002"], $numbers('search=suresh'));
        $this->assertSame(["INV-{$year}-0003"], $numbers('search=99887'));
        $this->assertSame(["INV-{$year}-0001"], $numbers("search={$year}-0001"));
        $this->assertSame(["INV-{$year}-0002"], $numbers('status=unpaid'));
        $this->assertSame(["INV-{$year}-0003"], $numbers('status=partly_paid'));

        $this->getJson('/api/admin/bills')
            ->assertJsonPath('data.0.items_count', 1)
            ->assertJsonPath('meta.total', 3);

        $this->getJson('/api/admin/bills?status=lost')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);
    }

    public function test_a_bill_shows_its_items_payments_and_the_shop_details(): void
    {
        config(['shop.name' => 'Sharma Hardware', 'shop.address' => 'MG Road, Jaipur', 'shop.phone' => '0141 2345678']);
        $hammer = $this->itemInStock('Claw Hammer', 260);
        $bill = $this->postBill('Ramesh Kumar', '9876543210', [[$hammer, 2]], [['method' => 'cash', 'amount' => 500]])->json('data');

        $this->getJson("/api/admin/bills/{$bill['id']}")
            ->assertOk()
            ->assertJsonPath('data.bill_number', $bill['bill_number'])
            ->assertJsonCount(1, 'data.items')
            ->assertJsonCount(1, 'data.payments')
            ->assertJsonPath('data.due_amount', 20)
            ->assertJsonPath('shop', ['name' => 'Sharma Hardware', 'address' => 'MG Road, Jaipur', 'phone' => '0141 2345678']);

        $this->getJson('/api/admin/bills/999')->assertNotFound();
    }

    public function test_a_bill_keeps_its_items_when_the_product_is_deleted_later(): void
    {
        $hammer = $this->itemInStock('Claw Hammer', 260);
        $bill = $this->postBill('Ramesh Kumar', '9876543210', [[$hammer, 1]])->json('data');

        $this->deleteJson("/api/admin/products/{$hammer->product_id}")->assertNoContent();

        $this->assertNull(Product::find($hammer->product_id));
        $this->getJson("/api/admin/bills/{$bill['id']}")
            ->assertOk()
            ->assertJsonPath('data.items.0.product_name', 'Claw Hammer')
            ->assertJsonPath('data.items.0.product_variant_id', null)
            ->assertJsonPath('data.total', 260);

        $this->assertSame(1, Bill::count());
    }
}
