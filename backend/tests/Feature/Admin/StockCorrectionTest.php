<?php

namespace Tests\Feature\Admin;

use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Admin\Concerns\MakesBills;
use Tests\TestCase;

class StockCorrectionTest extends TestCase
{
    use MakesBills;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::factory()->admin()->create(['name' => 'Ravi']));
    }

    private function correct(ProductVariant $variant, mixed $quantity, string $reason = 'counted', ?string $note = null): TestResponse
    {
        return $this->postJson("/api/admin/product-variants/{$variant->id}/stock-corrections", [
            'quantity' => $quantity,
            'reason' => $reason,
            'note' => $note,
        ]);
    }

    public function test_guests_and_non_admins_cannot_correct_stock(): void
    {
        $hammer = $this->itemInStock();
        $this->app['auth']->forgetGuards();

        $this->correct($hammer, 5)->assertUnauthorized();
        $this->getJson("/api/admin/products/{$hammer->product_id}/stock-history")->assertUnauthorized();

        Sanctum::actingAs(User::factory()->create());

        $this->correct($hammer, 5)->assertForbidden();
        $this->assertEquals(100, $hammer->fresh()->stock_quantity);
    }

    public function test_a_correction_sets_the_stock_and_logs_why(): void
    {
        $hammer = $this->itemInStock('Claw Hammer', stock: 10);
        $hammer->update(['purchase_price' => 280]);

        $this->correct($hammer, 8, 'damaged', 'Two handles cracked')
            ->assertCreated()
            ->assertJsonPath('data.product_name', 'Claw Hammer')
            ->assertJsonPath('data.unit', 'pc')
            ->assertJsonPath('data.stock_before', 10)
            ->assertJsonPath('data.stock_after', 8)
            ->assertJsonPath('data.reason', ['value' => 'damaged', 'label' => 'Damaged or lost'])
            ->assertJsonPath('data.note', 'Two handles cracked');

        $this->assertEquals(8, $hammer->fresh()->stock_quantity);
        $this->assertEquals(280, $hammer->fresh()->purchase_price);
    }

    public function test_corrections_are_checked(): void
    {
        $hammer = $this->itemInStock('Claw Hammer', stock: 10);

        $this->correct($hammer, 7.5)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['quantity' => 'Piece (pc) is counted in whole numbers.']);

        $this->correct($hammer, 10)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['quantity' => 'The stock is already 10 pc. Enter the count it should be.']);

        $this->correct($hammer, 12, 'other')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['note' => 'Say what happened, since the reason is "Other".']);

        $this->correct($hammer, -1, 'stolen')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['quantity', 'reason']);

        $this->postJson('/api/admin/product-variants/999/stock-corrections', ['quantity' => 1, 'reason' => 'counted'])
            ->assertNotFound();

        $this->assertDatabaseCount('stock_corrections', 0);
        $this->assertEquals(10, $hammer->fresh()->stock_quantity);
    }

    public function test_the_stock_history_lists_deliveries_and_corrections_newest_first(): void
    {
        config(['shop.timezone' => 'Asia/Kolkata']);
        $this->travelTo(Carbon::parse('2026-11-03 11:00', 'Asia/Kolkata'));
        $nails = $this->itemInStock('Wire Nails', 120, 10, decimals: true, size: '2 inch');
        $nails->update(['purchase_price' => 90]);
        $other = $this->itemInStock('Claw Hammer');

        // Entered today but received two days ago.
        $this->postJson('/api/admin/stock-entries', [
            'supplier_name' => 'Sharma Traders',
            'supplier_bill_number' => 'ST-4512',
            'received_on' => '2026-11-01',
            'items' => [['product_variant_id' => $nails->id, 'quantity' => 5, 'purchase_price' => 96]],
        ])->assertCreated();
        $this->correct($nails, 14.5, 'counted')->assertCreated();
        $this->correct($other, 3, 'damaged')->assertCreated();

        $this->getJson("/api/admin/products/{$nails->product_id}/stock-history")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.type', 'correction')
            ->assertJsonPath('data.0.date', '2026-11-03')
            ->assertJsonPath('data.0.variant_name', '2 inch')
            ->assertJsonPath('data.0.change', -0.5)
            ->assertJsonPath('data.0.stock_before', 15)
            ->assertJsonPath('data.0.stock_after', 14.5)
            ->assertJsonPath('data.0.reason.label', 'Counted again')
            ->assertJsonPath('data.0.by', 'Ravi')
            ->assertJsonPath('data.1.type', 'stock_in')
            ->assertJsonPath('data.1.date', '2026-11-01')
            ->assertJsonPath('data.1.change', 5)
            ->assertJsonPath('data.1.unit', 'kg')
            ->assertJsonPath('data.1.purchase_price', 96)
            ->assertJsonPath('data.1.cost_after', 92)
            ->assertJsonPath('data.1.supplier_name', 'Sharma Traders')
            ->assertJsonPath('data.1.supplier_bill_number', 'ST-4512');
    }
}
