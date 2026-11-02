<?php

namespace Tests\Feature\Admin;

use App\Models\Bill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Admin\Concerns\MakesBills;
use Tests\TestCase;

class BillPaymentTest extends TestCase
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

    private function pay(int $billId, string $method, float $amount, ?string $reference = null): TestResponse
    {
        return $this->postJson("/api/admin/bills/{$billId}/payments", [
            'method' => $method,
            'amount' => $amount,
            'reference' => $reference,
        ]);
    }

    public function test_guests_and_non_admins_cannot_take_payments(): void
    {
        $bill = $this->postBill('Ramesh Kumar', '9876543210', [[$this->itemInStock(), 1]])->json('data');
        $this->app['auth']->forgetGuards();

        $this->pay($bill['id'], 'cash', 100)->assertUnauthorized();

        Sanctum::actingAs(User::factory()->create());

        $this->pay($bill['id'], 'cash', 100)->assertForbidden();
        $this->assertDatabaseCount('bill_payments', 0);
    }

    public function test_a_bill_can_be_paid_off_in_parts(): void
    {
        // ₹10,000 bill: ₹5,000 paid on the day, then ₹3,000, then ₹2,000.
        $bill = $this->postBill('Ramesh Kumar', '9876543210', [[$this->itemInStock('Water Pump', 10000), 1]], [
            ['method' => 'cash', 'amount' => 5000],
        ])->json('data');

        $this->travel(3)->days();
        $this->pay($bill['id'], 'upi', 3000, 'UPI778899')
            ->assertOk()
            ->assertJsonPath('data.paid_amount', 8000)
            ->assertJsonPath('data.due_amount', 2000)
            ->assertJsonPath('data.status.value', 'partly_paid')
            ->assertJsonPath('data.payments.1.reference', 'UPI778899')
            ->assertJsonPath('shop.name', config('shop.name'));

        $this->travel(5)->days();
        $this->pay($bill['id'], 'bank_transfer', 2000)
            ->assertOk()
            ->assertJsonPath('data.paid_amount', 10000)
            ->assertJsonPath('data.due_amount', 0)
            ->assertJsonPath('data.status', ['value' => 'paid', 'label' => 'Paid'])
            ->assertJsonCount(3, 'data.payments');

        $this->assertSame(
            [['cash', '5000.00'], ['upi', '3000.00'], ['bank_transfer', '2000.00']],
            Bill::findOrFail($bill['id'])->payments->map(fn ($p) => [$p->method->value, $p->amount])->all(),
        );
        $this->assertDatabaseHas('bill_payments', ['amount' => 2000, 'received_by' => $this->admin->id]);
    }

    public function test_a_payment_goes_to_this_bill_only(): void
    {
        $item = $this->itemInStock('Cement Bag', 100);
        $older = $this->postBill('Ramesh Kumar', '9876543210', [[$item, 3]])->json('data');
        $this->travel(1)->minutes();
        $newer = $this->postBill('Ramesh Kumar', '9876543210', [[$item, 5]])->json('data');

        $this->pay($newer['id'], 'cash', 500)
            ->assertOk()
            ->assertJsonPath('data.status.value', 'paid');

        // Paying through the customer would have gone to the older bill first; here it did not.
        $this->assertEquals(300, Bill::findOrFail($older['id'])->due_amount);
        $this->getJson('/api/admin/customers/lookup?mobile=9876543210')->assertJsonPath('data.total_due', 300);
    }

    public function test_a_payment_cannot_be_more_than_the_bill_still_owes(): void
    {
        $bill = $this->postBill('Ramesh Kumar', '9876543210', [[$this->itemInStock('Claw Hammer', 260), 1]], [
            ['method' => 'cash', 'amount' => 60],
        ])->json('data');

        $this->pay($bill['id'], 'cash', 200.01)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['amount' => 'This is more than is due on this bill (₹200.00).']);

        $this->pay($bill['id'], 'cash', 200)->assertOk()->assertJsonPath('data.due_amount', 0);

        $this->pay($bill['id'], 'cash', 1)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['amount' => 'This bill is already paid in full.']);

        $this->assertDatabaseCount('bill_payments', 2);
    }

    public function test_payments_are_validated(): void
    {
        $bill = $this->postBill('Ramesh Kumar', '9876543210', [[$this->itemInStock(), 1]])->json('data');

        $this->pay($bill['id'], 'cheque', 0)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['method', 'amount']);

        $this->pay(999, 'cash', 10)->assertNotFound();
    }
}
