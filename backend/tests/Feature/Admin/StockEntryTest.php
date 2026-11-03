<?php

namespace Tests\Feature\Admin;

use App\Models\ProductVariant;
use App\Models\StockEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Admin\Concerns\MakesBills;
use Tests\TestCase;

class StockEntryTest extends TestCase
{
    use MakesBills;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create(['name' => 'Ravi']);
        Sanctum::actingAs($this->admin);
    }

    private function stocked(string $name, float $stock, float $cost, bool $decimals = false, ?string $size = null): ProductVariant
    {
        $variant = $this->itemInStock($name, $cost * 1.25, $stock, $decimals, $size);
        $variant->update(['purchase_price' => $cost]);

        return $variant;
    }

    /**
     * @param  list<array{0: ProductVariant, 1: float|int, 2: float|int}>  $lines  [size, quantity, purchase price]
     * @param  array<string, mixed>  $details
     */
    private function postEntry(array $lines, array $details = []): TestResponse
    {
        return $this->postJson('/api/admin/stock-entries', [
            'supplier_name' => 'Sharma Traders',
            'supplier_bill_number' => 'ST-4512',
            'received_on' => now(config('shop.timezone'))->toDateString(),
            ...$details,
            'items' => array_map(fn (array $line) => [
                'product_variant_id' => $line[0]->id,
                'quantity' => $line[1],
                'purchase_price' => $line[2],
            ], $lines),
        ]);
    }

    public function test_guests_and_non_admins_cannot_use_stock_in(): void
    {
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/admin/stock-entries')->assertUnauthorized();
        $this->postJson('/api/admin/stock-entries', [])->assertUnauthorized();
        $this->getJson('/api/admin/stock-entries/suppliers')->assertUnauthorized();
        $this->deleteJson('/api/admin/stock-entries/1')->assertUnauthorized();

        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/admin/stock-entries')->assertForbidden();
        $this->postJson('/api/admin/stock-entries', [])->assertForbidden();
    }

    public function test_a_delivery_adds_stock_and_averages_the_cost(): void
    {
        $hammer = $this->stocked('Claw Hammer', 10, 280);
        $nails = $this->stocked('Wire Nails', 2.5, 90, decimals: true, size: '2 inch');

        $this->postEntry([[$hammer, 20, 310], [$nails, 1.5, 100]], ['note' => 'Two cartons'])
            ->assertCreated()
            ->assertJsonPath('data.supplier_name', 'Sharma Traders')
            ->assertJsonPath('data.supplier_bill_number', 'ST-4512')
            ->assertJsonPath('data.note', 'Two cartons')
            ->assertJsonPath('data.total', 6350)
            ->assertJsonPath('data.created_by', 'Ravi')
            ->assertJsonPath('data.items.0.product_name', 'Claw Hammer')
            ->assertJsonPath('data.items.0.unit', 'pc')
            ->assertJsonPath('data.items.0.quantity', 20)
            ->assertJsonPath('data.items.0.purchase_price', 310)
            ->assertJsonPath('data.items.0.line_total', 6200)
            ->assertJsonPath('data.items.0.cost_before', 280)
            ->assertJsonPath('data.items.0.cost_after', 300)
            ->assertJsonPath('data.items.1.variant_name', '2 inch')
            ->assertJsonPath('data.items.1.line_total', 150);

        // 10 at ₹280 and 20 at ₹310 make 30 at ₹300.
        $this->assertEquals(30, $hammer->fresh()->stock_quantity);
        $this->assertEquals(300, $hammer->fresh()->purchase_price);
        // 2.5 kg at ₹90 and 1.5 kg at ₹100 make 4 kg at ₹93.75.
        $this->assertEquals(4, $nails->fresh()->stock_quantity);
        $this->assertEquals(93.75, $nails->fresh()->purchase_price);
    }

    public function test_with_no_stock_left_the_new_price_becomes_the_cost(): void
    {
        // Two more were sold than the count showed.
        $hammer = $this->stocked('Claw Hammer', -2, 280);

        $this->postEntry([[$hammer, 10, 310]])->assertCreated();

        $this->assertEquals(8, $hammer->fresh()->stock_quantity);
        $this->assertEquals(310, $hammer->fresh()->purchase_price);
    }

    public function test_items_counted_in_whole_units_cannot_come_in_fractions(): void
    {
        $hammer = $this->stocked('Claw Hammer', 10, 280);

        $this->postEntry([[$hammer, 1.5, 310]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.quantity' => 'Piece (pc) is counted in whole numbers.']);

        $this->assertDatabaseCount('stock_entries', 0);
        $this->assertEquals(10, $hammer->fresh()->stock_quantity);
    }

    public function test_deliveries_are_validated(): void
    {
        $hammer = $this->stocked('Claw Hammer', 10, 280);

        $this->postJson('/api/admin/stock-entries', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['received_on', 'items' => 'Add at least one item that came in.']);

        $this->postJson('/api/admin/stock-entries', [
            'supplier_name' => str_repeat('a', 101),
            'received_on' => now(config('shop.timezone'))->addDay()->toDateString(),
            'items' => [
                ['product_variant_id' => $hammer->id, 'quantity' => 0, 'purchase_price' => -1],
                ['product_variant_id' => $hammer->id, 'quantity' => 1, 'purchase_price' => 300],
                ['product_variant_id' => 999, 'quantity' => 1, 'purchase_price' => 10],
            ],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'supplier_name',
                'received_on' => 'The date received cannot be after today.',
                'items.0.quantity',
                'items.0.purchase_price',
                'items.1.product_variant_id' => 'This item is on the list twice. Change its quantity instead.',
                'items.2.product_variant_id' => 'This item no longer exists. Remove it from the list.',
            ]);

        $this->assertDatabaseCount('stock_entries', 0);
    }

    public function test_an_earlier_date_can_be_entered_and_the_list_goes_by_date_received(): void
    {
        $hammer = $this->stocked('Claw Hammer', 10, 280);
        $tape = $this->stocked('Measuring Tape', 5, 90);
        $today = now(config('shop.timezone'));

        $this->postEntry([[$hammer, 5, 280]], ['supplier_name' => 'Sharma Traders', 'received_on' => $today->toDateString()]);
        $this->postEntry([[$tape, 5, 90]], [
            'supplier_name' => 'Gupta & Sons',
            'supplier_bill_number' => 'GS-77',
            'received_on' => $today->copy()->subDays(3)->toDateString(),
        ]);

        $suppliers = fn (string $query) => collect($this->getJson("/api/admin/stock-entries?{$query}")->assertOk()->json('data'))
            ->pluck('supplier_name')
            ->all();

        $this->assertSame(['Sharma Traders', 'Gupta & Sons'], $suppliers(''));
        $this->assertSame(['Gupta & Sons'], $suppliers('search=gupta'));
        $this->assertSame(['Gupta & Sons'], $suppliers('search=GS-77'));
        $this->assertSame(['Sharma Traders'], $suppliers('search=hammer'));

        $this->getJson('/api/admin/stock-entries')
            ->assertJsonPath('data.0.items_count', 1)
            ->assertJsonPath('data.1.received_on', $today->copy()->subDays(3)->toDateString())
            ->assertJsonPath('meta.total', 2);
    }

    public function test_a_delivery_shows_what_came_in(): void
    {
        $hammer = $this->stocked('Claw Hammer', 10, 280);
        $entry = $this->postEntry([[$hammer, 20, 310]])->json('data');

        $this->getJson("/api/admin/stock-entries/{$entry['id']}")
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.quantity', 20)
            ->assertJsonPath('data.total', 6200);

        $this->getJson('/api/admin/stock-entries/999')->assertNotFound();
    }

    public function test_supplier_names_used_before_are_suggested(): void
    {
        $hammer = $this->stocked('Claw Hammer', 10, 280);
        $today = now(config('shop.timezone'));

        $this->postEntry([[$hammer, 1, 280]], ['supplier_name' => 'Gupta & Sons', 'received_on' => $today->copy()->subDays(5)->toDateString()]);
        $this->postEntry([[$hammer, 1, 280]], ['supplier_name' => 'Sharma Traders', 'received_on' => $today->copy()->subDay()->toDateString()]);
        $this->postEntry([[$hammer, 1, 280]], ['supplier_name' => 'Gupta & Sons', 'received_on' => $today->toDateString()]);
        $this->postEntry([[$hammer, 1, 280]], ['supplier_name' => null]);

        $this->getJson('/api/admin/stock-entries/suppliers')
            ->assertOk()
            ->assertExactJson(['data' => ['Gupta & Sons', 'Sharma Traders']]);
    }

    public function test_deleting_the_latest_delivery_takes_its_stock_out_and_restores_the_cost(): void
    {
        $hammer = $this->stocked('Claw Hammer', 10, 280);
        $entry = $this->postEntry([[$hammer, 20, 310]])->json('data');

        // 5 sold since.
        $this->postBill('Ramesh Kumar', '9876543210', [[$hammer->fresh(), 5]]);
        $this->assertEquals(25, $hammer->fresh()->stock_quantity);

        $this->deleteJson("/api/admin/stock-entries/{$entry['id']}")->assertNoContent();

        $this->assertEquals(5, $hammer->fresh()->stock_quantity);
        $this->assertEquals(280, $hammer->fresh()->purchase_price);
        $this->assertDatabaseCount('stock_entries', 0);
        $this->assertDatabaseCount('stock_entry_items', 0);
    }

    public function test_deleting_an_older_delivery_works_it_out_of_the_average(): void
    {
        $hammer = $this->stocked('Claw Hammer', 10, 280);
        $first = $this->postEntry([[$hammer, 20, 310]])->json('data');   // 30 at ₹300
        $this->postEntry([[$hammer, 10, 340]]);                           // 40 at ₹310

        $this->deleteJson("/api/admin/stock-entries/{$first['id']}")->assertNoContent();

        // As if only 10 at ₹280 and 10 at ₹340 had come in: 20 at ₹310.
        $this->assertEquals(20, $hammer->fresh()->stock_quantity);
        $this->assertEquals(310, $hammer->fresh()->purchase_price);
    }

    public function test_a_delivery_stays_readable_and_deletable_after_its_product_is_deleted(): void
    {
        $hammer = $this->stocked('Claw Hammer', 10, 280);
        $entry = $this->postEntry([[$hammer, 20, 310]])->json('data');

        $this->deleteJson("/api/admin/products/{$hammer->product_id}")->assertNoContent();

        $this->getJson("/api/admin/stock-entries/{$entry['id']}")
            ->assertOk()
            ->assertJsonPath('data.items.0.product_name', 'Claw Hammer')
            ->assertJsonPath('data.items.0.product_variant_id', null);

        $this->deleteJson("/api/admin/stock-entries/{$entry['id']}")->assertNoContent();
        $this->assertSame(0, StockEntry::count());
    }
}
