<?php

namespace Tests\Feature\Admin;

use App\Models\ActivityLog;
use App\Models\Bill;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockEntry;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Products bought in packs and sold loose: wire bought in 90 m coils and sold by the metre or
 * by the coil. Stock is counted in metres; the shopkeeper never has to convert.
 */
class PackTest extends TestCase
{
    use RefreshDatabase;

    private Unit $metre;

    private Unit $coil;

    private Unit $piece;

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->metre = Unit::factory()->create(['name' => 'Metre', 'short_name' => 'm', 'allow_decimal' => true]);
        $this->coil = Unit::factory()->create(['name' => 'Coil', 'short_name' => 'coil', 'allow_decimal' => false]);
        $this->piece = Unit::factory()->create(['name' => 'Piece', 'short_name' => 'pc', 'allow_decimal' => false]);
    }

    /**
     * 1 mm wire: bought in 90 m coils at ₹1,000, sold at ₹18 a metre or ₹1,350 a coil.
     */
    private function wire(float $stock = 500): ProductVariant
    {
        $product = Product::factory()->create([
            'name' => 'Copper Wire',
            'unit_id' => $this->metre->id,
            'pack_unit_id' => $this->coil->id,
            'pack_size' => 90,
        ]);

        return ProductVariant::factory()->for($product)->create([
            'name' => '1 mm',
            'purchase_price' => 1000 / 90,
            'selling_price' => 18,
            'pack_price' => 1350,
            'stock_quantity' => $stock,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function bill(array $items): TestResponse
    {
        return $this->postJson('/api/admin/bills', [
            'customer' => ['name' => 'Ramesh Kumar', 'mobile' => '9876543210'],
            'items' => $items,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function productPayload(array $overrides = [], array $variant = []): array
    {
        return [
            'name' => 'Copper Wire',
            'category_id' => Category::factory()->create()->id,
            'unit_id' => $this->metre->id,
            'pack_unit_id' => $this->coil->id,
            'pack_size' => 90,
            'location_area' => 'shop',
            'variants' => [[
                'name' => null,
                'purchase_price' => 11.1111,
                'selling_price' => 18,
                'pack_price' => 1350,
                'stock_quantity' => 535,
                'min_stock' => 180,
                ...$variant,
            ]],
            ...$overrides,
        ];
    }

    public function test_a_product_can_be_bought_in_packs_and_sold_loose(): void
    {
        $id = $this->postJson('/api/admin/products', $this->productPayload())
            ->assertCreated()
            ->assertJsonPath('data.unit.short_name', 'm')
            ->assertJsonPath('data.pack', ['unit' => ['id' => $this->coil->id, 'name' => 'Coil', 'short_name' => 'coil'], 'size' => 90])
            ->assertJsonPath('data.variants.0.pack_price', 1350)
            ->json('data.id');

        // The cost per metre keeps four decimals, so a coil's price comes back exactly.
        $variant = Product::find($id)->variants->first();
        $this->assertSame('11.1111', $variant->purchase_price);
        $this->assertSame('1000.00', number_format((float) $variant->purchase_price * 90, 2, '.', ''));

        $log = ActivityLog::query()->where('action', 'product.created')->first();
        $this->assertContains(['field' => 'Bought in', 'old' => null, 'new' => 'Coil of 90 m'], $log->changes);
        $this->assertContains(['field' => 'Purchase price per Coil', 'old' => null, 'new' => '₹1,000'], $log->changes);
        $this->assertContains(['field' => 'Coil price', 'old' => null, 'new' => '₹1,350'], $log->changes);
    }

    public function test_a_pack_must_be_counted_whole_and_fit_the_unit(): void
    {
        $this->postJson('/api/admin/products', $this->productPayload(['pack_unit_id' => $this->metre->id]))
            ->assertJsonValidationErrors(['pack_unit_id' => 'The pack must be different from the unit it is sold loose by.']);

        $kilo = Unit::factory()->create(['name' => 'Kilogram', 'short_name' => 'kg', 'allow_decimal' => true]);
        $this->postJson('/api/admin/products', $this->productPayload(['pack_unit_id' => $kilo->id]))
            ->assertJsonValidationErrors(['pack_unit_id' => 'Kilogram is not counted in whole packs. Choose a pack such as Box, Bag or Coil.']);

        $this->postJson('/api/admin/products', $this->productPayload(['pack_size' => null]))
            ->assertJsonValidationErrors(['pack_size' => 'Enter how many are in one pack.']);

        $this->postJson('/api/admin/products', $this->productPayload(['pack_size' => 1]))
            ->assertJsonValidationErrors(['pack_size' => 'A pack holds more than one.']);

        $box = Unit::factory()->create(['name' => 'Box', 'short_name' => 'box', 'allow_decimal' => false]);
        $this->postJson('/api/admin/products', $this->productPayload(
            ['unit_id' => $this->piece->id, 'pack_unit_id' => $box->id, 'pack_size' => 12.5],
            ['stock_quantity' => 100, 'min_stock' => null],
        ))->assertJsonValidationErrors(['pack_size' => 'One Box holds a whole number of Piece (pc).']);

        $this->assertSame(0, Product::count());
    }

    public function test_without_a_pack_the_pack_price_is_not_kept(): void
    {
        $id = $this->postJson('/api/admin/products', $this->productPayload(['pack_unit_id' => null, 'pack_size' => null]))
            ->assertCreated()
            ->assertJsonPath('data.pack', null)
            ->json('data.id');

        $this->assertNull(Product::find($id)->variants->first()->pack_price);
    }

    public function test_one_bill_can_sell_a_coil_and_a_few_metres_of_the_same_wire(): void
    {
        $wire = $this->wire(stock: 500);

        $bill = $this->bill([
            ['product_variant_id' => $wire->id, 'sold_as' => 'pack', 'quantity' => 1, 'price' => 1350],
            ['product_variant_id' => $wire->id, 'quantity' => 5, 'price' => 18],
        ])
            ->assertCreated()
            ->assertJsonPath('data.total', 1440)
            ->assertJsonPath('data.items.0.unit', 'coil')
            ->assertJsonPath('data.items.0.unit_size', 90)
            ->assertJsonPath('data.items.0.base_unit', 'm')
            ->assertJsonPath('data.items.0.sold_as', 'pack')
            ->assertJsonPath('data.items.0.list_price', 1350)
            ->assertJsonPath('data.items.1.unit', 'm')
            ->assertJsonPath('data.items.1.sold_as', 'loose')
            ->json('data');

        // 90 m for the coil and 5 m loose came off the shelf.
        $this->assertSame('405.000', $wire->fresh()->stock_quantity);

        // A coil cost the shop ₹1,000 and a metre ₹11.11, for profit.
        $items = Bill::find($bill['id'])->items;
        $this->assertSame(['1000.00', '11.11'], $items->pluck('cost_price')->all());
    }

    public function test_a_pack_is_sold_whole_and_each_item_is_on_a_bill_once_per_unit(): void
    {
        $wire = $this->wire();
        $hammer = ProductVariant::factory()->for(Product::factory()->create(['unit_id' => $this->piece->id]))->create();

        $this->bill([['product_variant_id' => $wire->id, 'sold_as' => 'pack', 'quantity' => 1.5, 'price' => 1350]])
            ->assertJsonValidationErrors(['items.0.quantity' => 'Coil (coil) is sold in whole numbers.']);

        $this->bill([
            ['product_variant_id' => $wire->id, 'quantity' => 5, 'price' => 18],
            ['product_variant_id' => $wire->id, 'sold_as' => 'loose', 'quantity' => 2, 'price' => 18],
        ])->assertJsonValidationErrors(['items.1.product_variant_id' => 'This item is on the bill twice. Change its quantity instead.']);

        $this->bill([['product_variant_id' => $hammer->id, 'sold_as' => 'pack', 'quantity' => 1, 'price' => 100]])
            ->assertJsonValidationErrors(['items.0.sold_as']);

        $this->assertSame(0, Bill::count());
        $this->assertSame('500.000', $wire->fresh()->stock_quantity);
    }

    public function test_editing_cancelling_and_returning_move_whole_coils_of_stock(): void
    {
        $wire = $this->wire(stock: 500);
        $bill = $this->bill([
            ['product_variant_id' => $wire->id, 'sold_as' => 'pack', 'quantity' => 1, 'price' => 1350],
            ['product_variant_id' => $wire->id, 'quantity' => 5, 'price' => 18],
        ])->json('data');
        [$coilLine, $looseLine] = $bill['items'];

        // One coil more: another 90 m comes off.
        $this->putJson("/api/admin/bills/{$bill['id']}", [
            'customer' => ['name' => 'Ramesh Kumar', 'mobile' => '9876543210'],
            'items' => [
                ['id' => $coilLine['id'], 'product_variant_id' => $wire->id, 'sold_as' => 'pack', 'quantity' => 2, 'price' => 1350],
                ['id' => $looseLine['id'], 'product_variant_id' => $wire->id, 'quantity' => 5, 'price' => 18],
            ],
        ])->assertOk()->assertJsonPath('data.total', 2790);
        $this->assertSame('315.000', $wire->fresh()->stock_quantity);

        $this->putJson("/api/admin/bills/{$bill['id']}", [
            'customer' => ['name' => 'Ramesh Kumar', 'mobile' => '9876543210'],
            'items' => [['id' => $coilLine['id'], 'product_variant_id' => $wire->id, 'sold_as' => 'pack', 'quantity' => 2.5, 'price' => 1350]],
        ])->assertJsonValidationErrors(['items.0.quantity' => 'Coil (coil) is sold in whole numbers.']);

        // A coil comes back: 90 m goes back on the shelf. Half a coil cannot come back.
        $this->postJson("/api/admin/bills/{$bill['id']}/returns", ['items' => [['bill_item_id' => $coilLine['id'], 'quantity' => 0.5]]])
            ->assertJsonValidationErrors(['items.0.quantity' => 'Coil (coil) is counted in whole numbers.']);
        $this->postJson("/api/admin/bills/{$bill['id']}/returns", ['items' => [['bill_item_id' => $coilLine['id'], 'quantity' => 1]]])
            ->assertOk()
            ->assertJsonPath('data.returns.0.items.0.unit', 'coil');
        $this->assertSame('405.000', $wire->fresh()->stock_quantity);

        // Cancelling puts back the coil still out and the 5 m.
        $this->postJson("/api/admin/bills/{$bill['id']}/cancel", ['reason' => 'Customer changed his mind'])->assertOk();
        $this->assertSame('500.000', $wire->fresh()->stock_quantity);
    }

    public function test_stock_comes_in_by_the_coil_at_the_coils_price(): void
    {
        $wire = $this->wire(stock: 0);

        $entry = $this->postJson('/api/admin/stock-entries', [
            'received_on' => now()->toDateString(),
            'items' => [['product_variant_id' => $wire->id, 'quantity' => 10, 'purchase_price' => 1000]],
        ])
            ->assertCreated()
            ->assertJsonPath('data.total', 10000)
            ->assertJsonPath('data.items.0.unit', 'coil')
            ->assertJsonPath('data.items.0.entered_as', 'pack')
            ->assertJsonPath('data.items.0.cost_after', 1000)
            ->json('data');

        $wire->refresh();
        $this->assertSame('900.000', $wire->stock_quantity);
        $this->assertSame('11.1111', $wire->purchase_price);

        // 45 m more, loose at ₹12 a metre: the average cost per metre takes it in.
        $this->postJson('/api/admin/stock-entries', [
            'received_on' => now()->toDateString(),
            'items' => [['product_variant_id' => $wire->id, 'entered_as' => 'loose', 'quantity' => 45, 'purchase_price' => 12]],
        ])->assertCreated()->assertJsonPath('data.items.0.unit', 'm');

        $wire->refresh();
        $this->assertSame('945.000', $wire->stock_quantity);
        $this->assertSame('11.1534', $wire->purchase_price); // (900 × 11.1111 + 45 × 12) ÷ 945

        $this->getJson("/api/admin/products/{$wire->product_id}/stock-history")
            ->assertOk()
            ->assertJsonPath('data.1.change', 10)
            ->assertJsonPath('data.1.unit', 'coil')
            ->assertJsonPath('data.1.purchase_price', 1000)
            ->assertJsonPath('data.1.cost_after', 1000);

        // A delivery entered by mistake takes its 10 coils back out.
        $this->deleteJson('/api/admin/stock-entries/'.StockEntry::query()->latest('id')->value('id'))->assertNoContent();
        $this->deleteJson("/api/admin/stock-entries/{$entry['id']}")->assertNoContent();
        $this->assertSame('0.000', $wire->fresh()->stock_quantity);
    }

    public function test_stock_in_counts_packs_whole_and_only_for_products_bought_in_packs(): void
    {
        $wire = $this->wire();
        $hammer = ProductVariant::factory()->for(Product::factory()->create(['unit_id' => $this->piece->id]))->create();

        $this->postJson('/api/admin/stock-entries', [
            'received_on' => now()->toDateString(),
            'items' => [
                ['product_variant_id' => $wire->id, 'quantity' => 2.5, 'purchase_price' => 1000],
                ['product_variant_id' => $hammer->id, 'entered_as' => 'pack', 'quantity' => 1, 'purchase_price' => 100],
            ],
        ])->assertJsonValidationErrors([
            'items.0.quantity' => 'Coil (coil) is counted in whole numbers.',
            'items.1.entered_as',
        ]);
    }

    public function test_the_sales_report_counts_coils_and_metres_together_in_metres(): void
    {
        $wire = $this->wire();
        $this->bill([
            ['product_variant_id' => $wire->id, 'sold_as' => 'pack', 'quantity' => 2, 'price' => 1350],
            ['product_variant_id' => $wire->id, 'quantity' => 15, 'price' => 18],
        ])->assertCreated();

        $today = now(config('shop.timezone'))->toDateString();

        $this->getJson("/api/admin/reports/sales?from={$today}&to={$today}")
            ->assertOk()
            ->assertJsonPath('data.products.0.quantity', 195)
            ->assertJsonPath('data.products.0.unit', 'm')
            ->assertJsonPath('data.products.0.amount', 2970)
            // ₹2,970 − (2 × ₹1,000 + 15 × ₹11.11)
            ->assertJsonPath('data.products.0.profit', 803.35);
    }
}
