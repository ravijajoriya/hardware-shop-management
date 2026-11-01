<?php

namespace Tests\Feature\Admin;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
    }

    /**
     * A paint sold in two sizes whose stock is worth 7,400 at cost and 9,000 at selling price.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_replace([
            'category_id' => Category::factory()->create()->id,
            'brand_id' => Brand::factory()->create()->id,
            'unit_id' => Unit::factory()->create(['allow_decimal' => true])->id,
            'name' => 'Apex Exterior Emulsion',
            'description' => 'Weatherproof wall paint.',
            'hsn_code' => '3209',
            'gst_rate' => 18,
            'location_area' => 'shop',
            'is_active' => true,
            'variants' => [
                ['name' => '1 L', 'sku' => 'APX-1L', 'purchase_price' => 300, 'selling_price' => 360, 'mrp' => 399, 'stock_quantity' => 10],
                ['name' => '4 L', 'sku' => 'APX-4L', 'purchase_price' => 1100, 'selling_price' => 1350, 'mrp' => 1499, 'stock_quantity' => 4],
            ],
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $product
     * @param  array<string, mixed>  $variant
     */
    private function productWithVariant(array $product = [], array $variant = []): Product
    {
        return Product::factory()
            ->has(ProductVariant::factory()->state($variant), 'variants')
            ->create($product);
    }

    public function test_guests_and_non_admins_cannot_access_products(): void
    {
        $product = $this->productWithVariant();

        $this->getJson('/api/admin/products')->assertUnauthorized();
        $this->getJson('/api/admin/products/options')->assertUnauthorized();
        $this->postJson('/api/admin/products', $this->payload())->assertUnauthorized();
        $this->getJson("/api/admin/products/{$product->id}")->assertUnauthorized();
        $this->putJson("/api/admin/products/{$product->id}", $this->payload())->assertUnauthorized();
        $this->deleteJson("/api/admin/products/{$product->id}")->assertUnauthorized();

        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/admin/products')->assertForbidden();
        $this->postJson('/api/admin/products', $this->payload())->assertForbidden();
    }

    public function test_admin_can_create_a_product_with_variants_and_see_its_stock_worth(): void
    {
        $this->actingAsAdmin();
        $payload = $this->payload();

        $this->postJson('/api/admin/products', $payload)
            ->assertCreated()
            ->assertJsonPath('data.name', 'Apex Exterior Emulsion')
            ->assertJsonPath('data.slug', 'apex-exterior-emulsion')
            ->assertJsonPath('data.category.id', $payload['category_id'])
            ->assertJsonPath('data.brand.id', $payload['brand_id'])
            ->assertJsonPath('data.unit.id', $payload['unit_id'])
            ->assertJsonPath('data.hsn_code', '3209')
            ->assertJsonPath('data.gst_rate', 18)
            ->assertJsonPath('data.image_url', null)
            ->assertJsonPath('data.images', [])
            ->assertJsonCount(2, 'data.variants')
            ->assertJsonPath('data.variants.0.name', '1 L')
            ->assertJsonPath('data.variants.0.mrp', 399)
            ->assertJsonPath('data.variants.0.stock_value.cost', 3000)
            ->assertJsonPath('data.variants.0.stock_value.selling', 3600)
            ->assertJsonPath('data.stock_quantity', 14)
            ->assertJsonPath('data.stock_value.cost', 7400)
            ->assertJsonPath('data.stock_value.selling', 9000);

        $this->assertDatabaseHas('products', ['name' => 'Apex Exterior Emulsion', 'hsn_code' => '3209']);
        $this->assertDatabaseCount('product_variants', 2);
        $this->assertDatabaseHas('product_variants', ['sku' => 'APX-4L', 'stock_quantity' => 4]);
    }

    public function test_gst_brand_and_variant_details_are_optional(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/admin/products', $this->payload([
            'brand_id' => null,
            'hsn_code' => null,
            'gst_rate' => null,
            'variants' => [['purchase_price' => 50, 'selling_price' => 65, 'stock_quantity' => 0]],
        ]))
            ->assertCreated()
            ->assertJsonPath('data.brand', null)
            ->assertJsonPath('data.hsn_code', null)
            ->assertJsonPath('data.gst_rate', null)
            ->assertJsonPath('data.variants.0.name', null)
            ->assertJsonPath('data.variants.0.sku', null)
            ->assertJsonPath('data.variants.0.mrp', null)
            ->assertJsonPath('data.stock_value.cost', 0);
    }

    public function test_a_brand_not_in_the_list_is_created_with_the_product(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/admin/products', $this->payload([
            'brand_id' => null,
            'new_brand_name' => 'Asian Paints',
        ]))
            ->assertCreated()
            ->assertJsonPath('data.brand.name', 'Asian Paints');

        $brand = Brand::where('name', 'Asian Paints')->firstOrFail();
        $this->assertSame('asian-paints', $brand->slug);
        $this->assertTrue($brand->is_active);
        $this->assertDatabaseHas('products', ['name' => 'Apex Exterior Emulsion', 'brand_id' => $brand->id]);
    }

    public function test_a_new_brand_can_be_added_while_editing_a_product(): void
    {
        $this->actingAsAdmin();
        $created = $this->postJson('/api/admin/products', $this->payload())->json('data');

        $this->putJson("/api/admin/products/{$created['id']}", $this->payload([
            'brand_id' => null,
            'new_brand_name' => 'Berger',
        ]))
            ->assertOk()
            ->assertJsonPath('data.brand.name', 'Berger');

        $this->assertDatabaseHas('brands', ['name' => 'Berger', 'slug' => 'berger']);
    }

    public function test_a_new_brand_is_checked_and_never_left_behind(): void
    {
        $this->actingAsAdmin();
        $haier = Brand::factory()->create(['name' => 'Haier']);
        $piece = Unit::factory()->create(['name' => 'Piece', 'short_name' => 'pc', 'allow_decimal' => false]);

        // A brand that already exists is pointed out rather than copied.
        $payload = $this->payload(['brand_id' => null, 'new_brand_name' => 'Haier']);
        $brands = Brand::count();

        $this->postJson('/api/admin/products', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['new_brand_name' => 'This brand already exists. Choose it from the brand list.']);
        $this->assertSame($brands, Brand::count());

        // Picking a brand and typing a new one at the same time is refused.
        $this->postJson('/api/admin/products', $this->payload(['brand_id' => $haier->id, 'new_brand_name' => 'Bosch']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['new_brand_name' => 'Choose a brand from the list or type a new one, not both.']);

        // When the product itself is rejected, its new brand is not created either.
        $payload = $this->payload([
            'brand_id' => null,
            'new_brand_name' => 'Bosch',
            'unit_id' => $piece->id,
            'variants' => [['purchase_price' => 10, 'selling_price' => 12, 'stock_quantity' => 1.5]],
        ]);
        $brands = Brand::count();

        $this->postJson('/api/admin/products', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['variants.0.stock_quantity']);
        $this->assertSame($brands, Brand::count());

        $this->assertDatabaseMissing('brands', ['name' => 'Bosch']);
        $this->assertDatabaseCount('products', 0);
    }

    public function test_where_a_product_is_kept_becomes_a_location_code(): void
    {
        $this->actingAsAdmin();

        $created = $this->postJson('/api/admin/products', $this->payload([
            'location_area' => 'godown',
            'location_floor' => 1,
            'location_rack' => 'b2',
            'location_shelf' => 4,
        ]))
            ->assertCreated()
            ->assertJsonPath('data.location', [
                'area' => 'godown',
                'floor' => 1,
                'rack' => 'B2',
                'shelf' => 4,
                'code' => 'GODOWN-1F-RB2-S4',
            ])
            ->json('data');

        $this->assertDatabaseHas('products', ['id' => $created['id'], 'location_code' => 'GODOWN-1F-RB2-S4']);

        // Moved to the shop's ground floor, off any rack: the old rack and shelf are cleared.
        $this->putJson("/api/admin/products/{$created['id']}", $this->payload([
            'location_area' => 'shop',
            'location_floor' => 0,
        ]))
            ->assertOk()
            ->assertJsonPath('data.location', [
                'area' => 'shop',
                'floor' => 0,
                'rack' => null,
                'shelf' => null,
                'code' => 'SHOP-GF',
            ]);
    }

    public function test_each_location_part_needs_the_one_before_it(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/admin/products', $this->payload(['location_area' => null]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['location_area' => 'Choose whether it is kept in the shop or the godown.']);

        $this->postJson('/api/admin/products', $this->payload(['location_area' => 'basement']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['location_area']);

        $this->postJson('/api/admin/products', $this->payload(['location_shelf' => 2]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['location_rack' => 'Enter the rack this shelf is in.']);

        $this->postJson('/api/admin/products', $this->payload(['location_rack' => '3']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['location_floor' => 'Choose the floor this rack is on.']);

        $this->postJson('/api/admin/products', $this->payload([
            'location_floor' => 21,
            'location_rack' => 'A-1',
            'location_shelf' => 0,
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'location_floor',
                'location_rack' => 'Use up to 4 letters or numbers for the rack, such as 3 or A.',
                'location_shelf',
            ]);

        $this->assertDatabaseCount('products', 0);
    }

    public function test_products_can_be_found_by_their_location_code(): void
    {
        $this->actingAsAdmin();
        $this->productWithVariant(['name' => 'Claw Hammer', 'location_code' => 'SHOP-GF-R3-S2']);
        $this->productWithVariant(['name' => 'Cement Bag', 'location_area' => 'godown', 'location_code' => 'GODOWN-GF']);

        $this->getJson('/api/admin/products?search=GF-R3')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Claw Hammer')
            ->assertJsonPath('data.0.location.code', 'SHOP-GF-R3-S2');

        $this->getJson('/api/admin/products?search=godown')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Cement Bag');
    }

    public function test_creating_a_product_validates_the_input(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/admin/products', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['category_id', 'unit_id', 'name', 'variants']);

        $this->postJson('/api/admin/products', $this->payload([
            'category_id' => 999,
            'brand_id' => 999,
            'unit_id' => 999,
            'slug' => 'Not A Slug',
            'hsn_code' => '12345',
            'gst_rate' => 101,
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'category_id', 'brand_id', 'unit_id', 'slug', 'gst_rate',
                'hsn_code' => 'The HSN code must be 4, 6 or 8 digits.',
            ]);

        $this->assertDatabaseCount('products', 0);
    }

    public function test_variants_are_validated(): void
    {
        $this->actingAsAdmin();
        $this->productWithVariant([], ['sku' => 'TAKEN']);

        $this->postJson('/api/admin/products', $this->payload(['variants' => []]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['variants' => 'Add at least one variant with its prices and stock.']);

        // Several variants need names to tell them apart.
        $this->postJson('/api/admin/products', $this->payload(['variants' => [
            ['purchase_price' => 10, 'selling_price' => 12, 'stock_quantity' => 1],
            ['purchase_price' => 10, 'selling_price' => 12, 'stock_quantity' => 1],
        ]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'variants.0.name' => 'Give each variant a name, such as its size or colour.',
                'variants.1.name',
            ]);

        $this->postJson('/api/admin/products', $this->payload(['variants' => [
            ['name' => 'Small', 'sku' => 'TAKEN', 'purchase_price' => -1, 'selling_price' => 12, 'mrp' => 10, 'stock_quantity' => -2],
            ['name' => 'small', 'sku' => 'NEW-1', 'purchase_price' => 10, 'selling_price' => 'abc', 'stock_quantity' => 1],
        ]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'variants.0.sku',
                'variants.0.purchase_price',
                'variants.0.mrp' => 'The MRP cannot be lower than the selling price.',
                'variants.0.stock_quantity',
                'variants.1.name' => 'Each variant needs a different name.',
                'variants.1.selling_price',
            ]);

        $this->postJson('/api/admin/products', $this->payload(['variants' => [
            ['name' => 'A', 'sku' => 'DUP', 'purchase_price' => 10, 'selling_price' => 12, 'stock_quantity' => 1],
            ['name' => 'B', 'sku' => 'dup', 'purchase_price' => 10, 'selling_price' => 12, 'stock_quantity' => 1],
        ]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['variants.1.sku' => 'Each variant needs a different SKU.']);

        $this->assertDatabaseCount('products', 1);
    }

    public function test_stock_must_be_a_whole_number_for_units_counted_in_whole_numbers(): void
    {
        $this->actingAsAdmin();
        $piece = Unit::factory()->create(['name' => 'Piece', 'short_name' => 'pc', 'allow_decimal' => false]);

        $this->postJson('/api/admin/products', $this->payload([
            'unit_id' => $piece->id,
            'variants' => [['purchase_price' => 10, 'selling_price' => 12, 'stock_quantity' => 2.5]],
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['variants.0.stock_quantity' => 'Stock must be a whole number for Piece (pc).']);

        $this->postJson('/api/admin/products', $this->payload([
            'unit_id' => $piece->id,
            'variants' => [['purchase_price' => 10, 'selling_price' => 12, 'stock_quantity' => 3]],
        ]))->assertCreated();

        // Units such as kilograms take fractions.
        $this->postJson('/api/admin/products', $this->payload([
            'variants' => [['purchase_price' => 80, 'selling_price' => 95, 'stock_quantity' => 12.75]],
        ]))
            ->assertCreated()
            ->assertJsonPath('data.stock_quantity', 12.75)
            ->assertJsonPath('data.stock_value.cost', 1020);
    }

    public function test_admin_can_view_a_product(): void
    {
        $this->actingAsAdmin();
        $product = $this->productWithVariant([], ['purchase_price' => 20, 'selling_price' => 30, 'stock_quantity' => 5]);

        $this->getJson("/api/admin/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $product->id)
            ->assertJsonPath('data.category.name', $product->category->name)
            ->assertJsonPath('data.unit.short_name', $product->unit->short_name)
            ->assertJsonPath('data.stock_value.cost', 100)
            ->assertJsonPath('data.stock_value.selling', 150);

        $this->getJson('/api/admin/products/999')->assertNotFound();
    }

    public function test_admin_can_update_a_product_and_its_variants(): void
    {
        $this->actingAsAdmin();
        $created = $this->postJson('/api/admin/products', $this->payload())->json('data');
        [$small, $large] = $created['variants'];
        $newCategory = Category::factory()->create();

        $this->putJson("/api/admin/products/{$created['id']}", $this->payload([
            'category_id' => $newCategory->id,
            'unit_id' => $created['unit']['id'],
            'name' => 'Apex Ultima Emulsion',
            'gst_rate' => null,
            'variants' => [
                // The 1 L tin's price and stock change, the 4 L tin is dropped and a 10 L tin is added.
                ['id' => $small['id'], 'name' => '1 L', 'sku' => 'APX-1L', 'purchase_price' => 320, 'selling_price' => 380, 'mrp' => 399, 'stock_quantity' => 7],
                ['name' => '10 L', 'sku' => 'APX-10L', 'purchase_price' => 2600, 'selling_price' => 3100, 'stock_quantity' => 2],
            ],
        ]))
            ->assertOk()
            ->assertJsonPath('data.name', 'Apex Ultima Emulsion')
            ->assertJsonPath('data.slug', 'apex-exterior-emulsion')
            ->assertJsonPath('data.category.id', $newCategory->id)
            ->assertJsonPath('data.gst_rate', null)
            ->assertJsonCount(2, 'data.variants')
            ->assertJsonPath('data.variants.0.id', $small['id'])
            ->assertJsonPath('data.variants.0.stock_quantity', 7)
            ->assertJsonPath('data.variants.1.name', '10 L')
            ->assertJsonPath('data.stock_value.cost', 7440)
            ->assertJsonPath('data.stock_value.selling', 8860);

        $this->assertDatabaseMissing('product_variants', ['id' => $large['id']]);
        $this->assertDatabaseCount('product_variants', 2);
    }

    public function test_update_checks_variant_ids_and_skus(): void
    {
        $this->actingAsAdmin();
        $this->productWithVariant([], ['sku' => 'TAKEN']);
        $other = $this->productWithVariant();
        $created = $this->postJson('/api/admin/products', $this->payload())->json('data');
        [$small, $large] = $created['variants'];
        $url = "/api/admin/products/{$created['id']}";

        // A variant of another product cannot be edited through this one.
        $this->putJson($url, $this->payload(['variants' => [
            ['id' => $other->variants->first()->id, 'purchase_price' => 1, 'selling_price' => 2, 'stock_quantity' => 0],
        ]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['variants.0.id']);

        // Another product's SKU is taken.
        $this->putJson($url, $this->payload(['variants' => [
            ['id' => $small['id'], 'sku' => 'TAKEN', 'purchase_price' => 1, 'selling_price' => 2, 'stock_quantity' => 0],
        ]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['variants.0.sku']);

        // Its own variants may keep or swap their SKUs.
        $this->putJson($url, $this->payload(['variants' => [
            ['id' => $small['id'], 'name' => '1 L', 'sku' => 'APX-4L', 'purchase_price' => 300, 'selling_price' => 360, 'stock_quantity' => 10],
            ['id' => $large['id'], 'name' => '4 L', 'sku' => 'APX-1L', 'purchase_price' => 1100, 'selling_price' => 1350, 'stock_quantity' => 4],
        ]]))
            ->assertOk()
            ->assertJsonPath('data.variants.0.sku', 'APX-4L')
            ->assertJsonPath('data.variants.1.sku', 'APX-1L');
    }

    public function test_admin_can_delete_a_product_with_its_variants(): void
    {
        $this->actingAsAdmin();
        $product = $this->productWithVariant();

        $this->deleteJson("/api/admin/products/{$product->id}")->assertNoContent();

        $this->assertDatabaseMissing('products', ['id' => $product->id]);
        $this->assertDatabaseCount('product_variants', 0);
    }

    public function test_list_is_paginated_by_name_with_a_stock_worth_summary(): void
    {
        $this->actingAsAdmin();
        $this->productWithVariant(['name' => 'Wood Screws'], ['purchase_price' => 2, 'selling_price' => 3, 'stock_quantity' => 500]);
        $this->productWithVariant(['name' => 'Claw Hammer'], ['purchase_price' => 250, 'selling_price' => 320, 'stock_quantity' => 4]);
        $this->productWithVariant(['name' => 'Measuring Tape'], ['purchase_price' => 90, 'selling_price' => 120, 'stock_quantity' => 10]);

        $this->getJson('/api/admin/products?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Claw Hammer')
            ->assertJsonPath('data.1.name', 'Measuring Tape')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('summary.products', 3)
            ->assertJsonPath('summary.cost_value', 2900)
            ->assertJsonPath('summary.selling_value', 3980);

        $this->getJson('/api/admin/products?per_page=2&page=2')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Wood Screws');
    }

    public function test_list_can_be_searched_and_filtered(): void
    {
        $this->actingAsAdmin();
        $tools = Category::factory()->create(['name' => 'Tools']);
        $handTools = Category::factory()->create(['name' => 'Hand Tools', 'parent_id' => $tools->id]);
        $paint = Category::factory()->create(['name' => 'Paint']);
        $stanley = Brand::factory()->create();

        $this->productWithVariant(
            ['name' => 'Claw Hammer', 'category_id' => $handTools->id, 'brand_id' => $stanley->id],
            ['sku' => 'HAM-500', 'purchase_price' => 250, 'selling_price' => 320, 'stock_quantity' => 4],
        );
        $this->productWithVariant(
            ['name' => 'Power Drill', 'category_id' => $tools->id],
            ['purchase_price' => 2000, 'selling_price' => 2500, 'stock_quantity' => 1],
        );
        $this->productWithVariant(['name' => 'Wall Primer', 'category_id' => $paint->id]);

        $names = fn (string $query) => collect($this->getJson("/api/admin/products?{$query}")->assertOk()->json('data'))
            ->pluck('name')
            ->all();

        $this->assertSame(['Claw Hammer'], $names('search=hammer'));
        $this->assertSame(['Claw Hammer'], $names('search=HAM-5'));
        $this->assertSame(['Claw Hammer', 'Power Drill'], $names("category_id={$tools->id}"));
        $this->assertSame(['Claw Hammer'], $names("category_id={$handTools->id}"));
        $this->assertSame(['Claw Hammer'], $names("brand_id={$stanley->id}"));

        // The summary covers the filtered products only; Tools includes its subcategory.
        $this->getJson("/api/admin/products?category_id={$tools->id}")
            ->assertJsonPath('summary.products', 2)
            ->assertJsonPath('summary.cost_value', 3000)
            ->assertJsonPath('summary.selling_value', 3780);

        $this->getJson('/api/admin/products?category_id=999&per_page=500')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['category_id', 'per_page']);
    }

    public function test_form_options_list_categories_as_a_tree_with_brands_and_units(): void
    {
        $this->actingAsAdmin();
        $tools = Category::factory()->create(['name' => 'Tools']);
        Category::factory()->create(['name' => 'Hammers', 'parent_id' => $tools->id]);
        Category::factory()->create(['name' => 'Adhesives']);
        Brand::factory()->create(['name' => 'Stanley']);
        Unit::factory()->create(['name' => 'Kilogram', 'short_name' => 'kg', 'allow_decimal' => true]);

        $this->getJson('/api/admin/products/options')
            ->assertOk()
            ->assertJsonPath('data.categories.0.name', 'Adhesives')
            ->assertJsonPath('data.categories.0.depth', 0)
            ->assertJsonPath('data.categories.1.name', 'Tools')
            ->assertJsonPath('data.categories.2.name', 'Hammers')
            ->assertJsonPath('data.categories.2.depth', 1)
            ->assertJsonPath('data.brands.0.name', 'Stanley')
            ->assertJsonPath('data.units.0.short_name', 'kg')
            ->assertJsonPath('data.units.0.allow_decimal', true);
    }
}
