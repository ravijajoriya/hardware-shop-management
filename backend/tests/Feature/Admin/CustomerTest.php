<?php

namespace Tests\Feature\Admin;

use App\Models\Bill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Admin\Concerns\MakesBills;
use Tests\TestCase;

class CustomerTest extends TestCase
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

    /**
     * Ramesh has three bills, made a minute apart, with ₹200, ₹300 and ₹500 due.
     *
     * @return list<string> The bill numbers, oldest first.
     */
    private function rameshWithDues(): array
    {
        $item = $this->itemInStock('Cement Bag', 100);
        $numbers = [];

        foreach ([[3, 100], [5, 200], [5, 0]] as [$quantity, $paid]) {
            $payments = $paid ? [['method' => 'cash', 'amount' => $paid]] : [];
            $numbers[] = $this->postBill('Ramesh Kumar', '9876543210', [[$item, $quantity]], $payments)->json('data.bill_number');
            $this->travel(1)->minutes();
        }

        return $numbers;
    }

    public function test_guests_and_non_admins_cannot_see_customers(): void
    {
        $customer = User::factory()->customer()->create();
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/admin/customers')->assertUnauthorized();
        $this->getJson('/api/admin/customers/lookup?mobile=9876543210')->assertUnauthorized();
        $this->getJson("/api/admin/customers/{$customer->id}")->assertUnauthorized();
        $this->postJson("/api/admin/customers/{$customer->id}/payments")->assertUnauthorized();

        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/admin/customers')->assertForbidden();
    }

    public function test_a_customer_is_found_by_mobile_number_while_billing(): void
    {
        $this->rameshWithDues();

        $this->getJson('/api/admin/customers/lookup?mobile='.urlencode('+91 98765 43210'))
            ->assertOk()
            ->assertJsonPath('data.name', 'Ramesh Kumar')
            ->assertJsonPath('data.mobile', '9876543210')
            ->assertJsonPath('data.bills_count', 3)
            ->assertJsonPath('data.total_due', 1000);

        // A new number is not an error: there is simply no customer yet.
        $this->getJson('/api/admin/customers/lookup?mobile=9123456789')
            ->assertOk()
            ->assertExactJson(['data' => null]);

        $this->getJson('/api/admin/customers/lookup?mobile=12345')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['mobile']);
    }

    public function test_customers_are_listed_with_their_totals(): void
    {
        $this->rameshWithDues();
        $hammer = $this->itemInStock('Claw Hammer', 260);
        $this->postBill('Anil Sharma', '9123456780', [[$hammer, 1]], [['method' => 'upi', 'amount' => 260]]);
        User::factory()->customer()->create(['name' => 'Zoya Khan', 'mobile' => '9000000001']);

        $this->getJson('/api/admin/customers')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.name', 'Anil Sharma')
            ->assertJsonPath('data.0.total_due', 0)
            ->assertJsonPath('data.1.name', 'Ramesh Kumar')
            ->assertJsonPath('data.1.bills_count', 3)
            ->assertJsonPath('data.1.total_billed', 1300)
            ->assertJsonPath('data.1.total_due', 1000)
            ->assertJsonPath('data.2.name', 'Zoya Khan')
            ->assertJsonPath('data.2.bills_count', 0)
            ->assertJsonPath('data.2.total_due', 0);

        $names = fn (string $query) => collect($this->getJson("/api/admin/customers?{$query}")->json('data'))->pluck('name')->all();

        $this->assertSame(['Ramesh Kumar'], $names('with_due=1'));
        $this->assertSame(['Anil Sharma'], $names('search=91234'));
        $this->assertSame(['Zoya Khan'], $names('search=zoya'));
    }

    public function test_a_customer_shows_their_bills_and_payments(): void
    {
        $numbers = $this->rameshWithDues();
        $customer = User::where('mobile', '9876543210')->firstOrFail();

        $this->getJson("/api/admin/customers/{$customer->id}")
            ->assertOk()
            ->assertJsonPath('data.total_due', 1000)
            ->assertJsonCount(3, 'data.bills')
            ->assertJsonPath('data.bills.0.bill_number', $numbers[2])
            ->assertJsonCount(2, 'data.payments')
            ->assertJsonPath('data.payments.0.bill.bill_number', $numbers[1])
            ->assertJsonPath('data.payments.0.amount', 200);

        // Admins are not customers.
        $this->getJson("/api/admin/customers/{$this->admin->id}")->assertNotFound();
    }

    public function test_a_payment_towards_dues_clears_the_oldest_bills_first(): void
    {
        [$first, $second, $third] = $this->rameshWithDues();
        $customer = User::where('mobile', '9876543210')->firstOrFail();

        $this->postJson("/api/admin/customers/{$customer->id}/payments", [
            'method' => 'upi',
            'amount' => 400,
            'reference' => 'UPI778899',
        ])
            ->assertOk()
            ->assertJsonPath('applied_to', [
                ['bill_number' => $first, 'amount' => 200],
                ['bill_number' => $second, 'amount' => 200],
            ])
            ->assertJsonPath('data.total_due', 600);

        $bill = fn (string $number) => Bill::where('bill_number', $number)->firstOrFail();

        $this->assertSame('paid', $bill($first)->status->value);
        $this->assertEquals(0, $bill($first)->due_amount);
        $this->assertSame('partly_paid', $bill($second)->status->value);
        $this->assertEquals(100, $bill($second)->due_amount);
        $this->assertEquals(400, $bill($second)->paid_amount);
        $this->assertEquals(500, $bill($third)->due_amount);
        $this->assertSame('unpaid', $bill($third)->status->value);

        $this->assertDatabaseHas('bill_payments', [
            'bill_id' => $bill($second)->id,
            'method' => 'upi',
            'amount' => 200,
            'reference' => 'UPI778899',
            'received_by' => $this->admin->id,
        ]);
    }

    public function test_a_payment_cannot_be_more_than_what_is_due(): void
    {
        $this->rameshWithDues();
        $customer = User::where('mobile', '9876543210')->firstOrFail();

        $this->postJson("/api/admin/customers/{$customer->id}/payments", ['method' => 'cash', 'amount' => 1000.01])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['amount' => 'This is more than the customer owes (₹1,000.00).']);

        $this->postJson("/api/admin/customers/{$customer->id}/payments", ['method' => 'cash', 'amount' => 1000])
            ->assertOk()
            ->assertJsonPath('data.total_due', 0);

        $this->postJson("/api/admin/customers/{$customer->id}/payments", ['method' => 'cash', 'amount' => 10])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['amount' => 'This customer has nothing due.']);

        $this->postJson("/api/admin/customers/{$customer->id}/payments", ['method' => 'card', 'amount' => -5])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['method', 'amount']);
    }
}
