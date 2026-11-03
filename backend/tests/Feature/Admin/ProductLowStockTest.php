<?php

namespace Tests\Feature\Admin;

use App\Models\ActivityLog;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductLowStockTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::factory()->admin()->create());
    }

    /**
     * A paint in two sizes: 3 tins of 1 L where 5 should be kept, and 6 tins of 4 L where 4 should.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function paint(array $overrides = []): array
    {
        return array_replace([
            'category_id' => Category::factory()->create()->id,
            'brand_id' => Brand::factory()->create()->id,
            'unit_id' => Unit::firstOrCreate(['short_name' => 'tin'], ['name' => 'Tin', 'allow_decimal' => false])->id,
            'name' => 'Apex Exterior Emulsion',
            'location_area' => 'shop',
            'is_active' => true,
            'variants' => [
                ['name' => '1 L', 'purchase_price' => 300, 'selling_price' => 360, 'stock_quantity' => 3, 'min_stock' => 5],
                ['name' => '4 L', 'purchase_price' => 1100, 'selling_price' => 1350, 'stock_quantity' => 6, 'min_stock' => 4],
            ],
        ], $overrides);
    }

    /**
     * A product with one variant holding this much stock, with this minimum.
     *
     * @param  array<string, mixed>  $product
     */
    private function product(string $name, float $stock, ?float $minimum, array $product = []): Product
    {
        return Product::factory()
            ->has(ProductVariant::factory()->state(['stock_quantity' => $stock, 'min_stock' => $minimum]), 'variants')
            ->create(['name' => $name, ...$product]);
    }

    public function test_a_product_is_low_when_any_variant_is_below_its_minimum_stock(): void
    {
        $this->postJson('/api/admin/products', $this->paint())
            ->assertCreated()
            ->assertJsonPath('data.low_stock', true)
            ->assertJsonPath('data.variants.0.min_stock', 5)
            ->assertJsonPath('data.variants.0.low_stock', true)
            ->assertJsonPath('data.variants.1.min_stock', 4)
            ->assertJsonPath('data.variants.1.low_stock', false);

        // Exactly at the minimum is not below it, and a variant without a minimum is never low.
        $this->postJson('/api/admin/products', $this->paint([
            'name' => 'Apex Primer',
            'variants' => [
                ['name' => '1 L', 'purchase_price' => 200, 'selling_price' => 250, 'stock_quantity' => 5, 'min_stock' => 5],
                ['name' => '4 L', 'purchase_price' => 700, 'selling_price' => 850, 'stock_quantity' => 0],
            ],
        ]))
            ->assertCreated()
            ->assertJsonPath('data.low_stock', false)
            ->assertJsonPath('data.variants.1.min_stock', null)
            ->assertJsonPath('data.variants.1.low_stock', false);
    }

    public function test_the_minimum_stock_can_be_changed_or_cleared_and_the_change_is_logged(): void
    {
        $product = $this->postJson('/api/admin/products', $this->paint())->json('data');
        [$small, $large] = $product['variants'];
        $variants = [
            ['id' => $small['id'], 'name' => '1 L', 'purchase_price' => 300, 'selling_price' => 360, 'min_stock' => 2],
            ['id' => $large['id'], 'name' => '4 L', 'purchase_price' => 1100, 'selling_price' => 1350, 'min_stock' => null],
        ];

        $this->putJson("/api/admin/products/{$product['id']}", $this->paint(['unit_id' => $product['unit']['id'], 'variants' => $variants]))
            ->assertOk()
            ->assertJsonPath('data.low_stock', false)
            ->assertJsonPath('data.variants.0.min_stock', 2)
            ->assertJsonPath('data.variants.1.min_stock', null);

        $changes = collect(ActivityLog::latest('id')->first()->changes)->keyBy('field');
        $this->assertSame(['field' => 'Minimum stock (1 L)', 'old' => '5 tin', 'new' => '2 tin'], $changes['Minimum stock (1 L)']);
        $this->assertSame(['field' => 'Minimum stock (4 L)', 'old' => '4 tin', 'new' => null], $changes['Minimum stock (4 L)']);
    }

    public function test_the_minimum_stock_is_checked(): void
    {
        $this->postJson('/api/admin/products', $this->paint([
            'variants' => [['purchase_price' => 300, 'selling_price' => 360, 'stock_quantity' => 3, 'min_stock' => 2.5]],
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['variants.0.min_stock' => 'Minimum stock must be a whole number for Tin (tin).']);

        $this->postJson('/api/admin/products', $this->paint([
            'variants' => [['purchase_price' => 300, 'selling_price' => 360, 'stock_quantity' => 3, 'min_stock' => -1]],
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['variants.0.min_stock']);

        // Units such as kilograms take fractions.
        $this->postJson('/api/admin/products', $this->paint([
            'unit_id' => Unit::factory()->create(['allow_decimal' => true])->id,
            'variants' => [['purchase_price' => 80, 'selling_price' => 95, 'stock_quantity' => 1.25, 'min_stock' => 2.5]],
        ]))
            ->assertCreated()
            ->assertJsonPath('data.variants.0.min_stock', 2.5)
            ->assertJsonPath('data.low_stock', true);
    }

    public function test_the_list_finds_and_counts_products_low_on_stock(): void
    {
        $this->product('Claw Hammer', 2, 5);
        $this->product('Wire Nails', 40, 10);
        $this->product('Measuring Tape', 0, null);
        $this->product('Hand Saw', 5, 5);
        // No longer sold, so it does not need ordering.
        $this->product('Old Drill', 0, 3, ['is_active' => false]);
        Product::factory()
            ->has(ProductVariant::factory()->count(2)->sequence(
                ['name' => 'Small', 'stock_quantity' => 50, 'min_stock' => 10],
                ['name' => 'Large', 'stock_quantity' => 1, 'min_stock' => 2],
            ), 'variants')
            ->create(['name' => 'Wood Screws']);

        $names = fn (string $query) => collect($this->getJson("/api/admin/products?{$query}")->assertOk()->json('data'))->pluck('name')->all();

        $this->assertSame(['Claw Hammer', 'Wood Screws'], $names('low_stock=1'));
        $this->assertSame(['Wood Screws'], $names('low_stock=1&search=screw'));
        $this->assertCount(6, $names('low_stock=0'));

        $this->getJson('/api/admin/products')
            ->assertJsonPath('summary.products', 6)
            ->assertJsonPath('summary.low_stock', 2);

        // The count follows the other filters.
        $this->getJson('/api/admin/products?search=hammer')
            ->assertJsonPath('summary.products', 1)
            ->assertJsonPath('summary.low_stock', 1);

        $this->getJson('/api/admin/products?low_stock=maybe')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['low_stock']);
    }

    public function test_the_dashboard_counts_products_low_on_stock_until_more_comes_in(): void
    {
        $hammer = $this->product('Claw Hammer', 2, 5);
        $this->product('Wire Nails', 1, 3);
        $this->product('Measuring Tape', 0, null);

        $this->getJson('/api/admin/dashboard/summary')->assertJsonPath('data.low_stock', 2);

        $hammer->variants()->update(['stock_quantity' => 8]);

        $this->getJson('/api/admin/dashboard/summary')->assertJsonPath('data.low_stock', 1);
    }
}
