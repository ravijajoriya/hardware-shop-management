<?php

namespace Tests\Feature\Admin;

use App\Models\ActivityLog;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Unit;
use App\Models\User;
use App\Support\ProductSheet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ProductImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::factory()->admin()->create());

        Unit::firstOrCreate(['short_name' => 'pc'], ['name' => 'Piece', 'allow_decimal' => false]);
        Unit::firstOrCreate(['short_name' => 'kg'], ['name' => 'Kilogram', 'allow_decimal' => true]);
        Unit::firstOrCreate(['short_name' => 'ltr'], ['name' => 'Litre', 'allow_decimal' => true]);
    }

    /**
     * A row in the template's column order, from values by column.
     *
     * @param  array<string, mixed>  $values
     * @return list<mixed>
     */
    private function row(array $values): array
    {
        return array_map(fn (string $key) => $values[$key] ?? null, array_keys(ProductSheet::COLUMNS));
    }

    /**
     * A product that is fine as it is, with any values changed.
     *
     * @param  array<string, mixed>  $values
     * @return list<mixed>
     */
    private function good(array $values = []): array
    {
        return $this->row([
            'name' => 'Claw Hammer', 'category' => 'Hand Tools', 'unit' => 'pc',
            'purchase_price' => 250, 'selling_price' => 320, 'stock' => 15, ...$values,
        ]);
    }

    /**
     * An .xlsx file with the template's headings and these rows.
     *
     * @param  list<list<mixed>>  $rows
     * @param  list<string>|null  $headings
     */
    private function excel(array $rows, ?array $headings = null, string $name = 'products.xlsx'): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray(
            [$headings ?? array_column(ProductSheet::COLUMNS, 'heading'), ...$rows],
            null,
            'A1',
            true,
        );

        $path = tempnam(sys_get_temp_dir(), 'import').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return new UploadedFile($path, $name, null, null, true);
    }

    /**
     * A paint in two sizes in a new category with a new brand, a hammer in a category and with
     * a brand already in the app, and nails sold by weight.
     *
     * @return list<list<mixed>>
     */
    private function shopRows(): array
    {
        return [
            $this->row([
                'name' => 'Apex Exterior Emulsion', 'size' => '1 L', 'category' => 'Paints > Exterior', 'brand' => 'Asian Paints',
                'unit' => 'ltr', 'sku' => 'APX-1L', 'purchase_price' => 300, 'selling_price' => 360, 'mrp' => 399, 'stock' => 10,
                'min_stock' => 4, 'area' => 'Godown', 'floor' => 1, 'rack' => 3, 'shelf' => 2, 'hsn' => '3209', 'gst' => 18,
                'description' => 'Weatherproof wall paint.', 'active' => 'Yes',
            ]),
            // The second size shares the product's details, which need not be written again.
            $this->row([
                'name' => 'Apex Exterior Emulsion', 'size' => '4 L', 'sku' => 'APX-4L',
                'purchase_price' => 1100, 'selling_price' => 1350, 'mrp' => 1499, 'stock' => 6,
            ]),
            $this->good(['brand' => 'stanley', 'sku' => 'HAM-500', 'area' => 'Shop', 'floor' => 'Ground', 'rack' => 'a', 'shelf' => 1]),
            $this->row([
                'name' => 'Wire Nails', 'size' => '2 inch', 'category' => 'Hardware > Nails', 'unit' => 'kg',
                'purchase_price' => '₹90', 'selling_price' => '1,20', 'stock' => 25.5,
            ]),
        ];
    }

    private function check(UploadedFile $file): TestResponse
    {
        return $this->post('/api/admin/products/import/check', ['file' => $file], ['Accept' => 'application/json']);
    }

    private function import(UploadedFile $file, bool $skipProblems = false): TestResponse
    {
        return $this->post('/api/admin/products/import', ['file' => $file, 'skip_problems' => $skipProblems ? '1' : '0'], ['Accept' => 'application/json']);
    }

    public function test_guests_and_non_admins_cannot_import(): void
    {
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/admin/products/import/template')->assertUnauthorized();
        $this->check($this->excel([$this->good()]))->assertUnauthorized();

        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/admin/products/import/template')->assertForbidden();
        $this->import($this->excel([$this->good()]))->assertForbidden();
    }

    public function test_the_template_has_every_column_an_example_and_help(): void
    {
        Category::factory()->create(['name' => 'Hand Tools']);
        Brand::factory()->create(['name' => 'Stanley']);

        $response = $this->get('/api/admin/products/import/template')->assertOk();
        $this->assertStringContainsString('products-template.xlsx', $response->headers->get('Content-Disposition'));

        $path = tempnam(sys_get_temp_dir(), 'template').'.xlsx';
        file_put_contents($path, $response->getContent());
        $spreadsheet = IOFactory::load($path);

        $this->assertSame(['Products', 'Example', 'Help'], $spreadsheet->getSheetNames());
        $headings = $spreadsheet->getSheetByName('Products')->toArray()[0];
        $this->assertSame('Product name *', $headings[0]);
        $this->assertSame('Size or variant', $headings[1]);
        $this->assertCount(count(ProductSheet::COLUMNS), $headings);

        $help = collect($spreadsheet->getSheetByName('Help')->toArray())->flatten()->filter()->all();
        $this->assertContains('pc', $help);
        $this->assertContains('Hand Tools', $help);
        $this->assertContains('Stanley', $help);

        // The template, with its example rows copied in, imports as it is.
        $example = $spreadsheet->getSheetByName('Example')->toArray();
        $this->check($this->excel(array_slice($example, 1), $example[0]))
            ->assertOk()
            ->assertJsonPath('data.problems', [])
            ->assertJsonPath('data.ready', ['products' => 3, 'sizes' => 4]);
    }

    public function test_a_check_shows_what_would_happen_without_saving_anything(): void
    {
        Category::factory()->create(['name' => 'Hand Tools']);
        Brand::factory()->create(['name' => 'Stanley']);

        $this->check($this->excel($this->shopRows()))
            ->assertOk()
            ->assertJsonPath('data.file', 'products.xlsx')
            ->assertJsonPath('data.rows', 4)
            ->assertJsonPath('data.ready', ['products' => 3, 'sizes' => 4])
            ->assertJsonPath('data.problems', [])
            ->assertJsonPath('data.existing', [])
            ->assertJsonPath('data.new_categories', ['Paints', 'Paints > Exterior', 'Hardware', 'Hardware > Nails'])
            ->assertJsonPath('data.new_brands', ['Asian Paints']);

        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('categories', 1);
        $this->assertDatabaseCount('brands', 1);
    }

    public function test_importing_adds_the_products_with_their_sizes_categories_brands_and_place(): void
    {
        $tools = Category::factory()->create(['name' => 'Hand Tools']);
        $stanley = Brand::factory()->create(['name' => 'Stanley']);

        $this->import($this->excel($this->shopRows()))
            ->assertCreated()
            ->assertJsonPath('data', [
                'products' => 3, 'sizes' => 4, 'categories' => 4, 'brands' => 1, 'existing' => 0, 'left_out_rows' => 0,
            ]);

        $paint = Product::where('name', 'Apex Exterior Emulsion')->with('variants', 'category.parent', 'brand', 'unit')->firstOrFail();
        $this->assertSame('Exterior', $paint->category->name);
        $this->assertSame('Paints', $paint->category->parent->name);
        $this->assertSame('Asian Paints', $paint->brand->name);
        $this->assertSame('ltr', $paint->unit->short_name);
        $this->assertSame('GODOWN-1F-R3-S2', $paint->location_code);
        $this->assertSame('3209', $paint->hsn_code);
        $this->assertEquals(18, $paint->gst_rate);
        $this->assertSame('Weatherproof wall paint.', $paint->description);
        $this->assertSame(
            [['1 L', 'APX-1L', '300.00', '360.00', '399.00', '10.000', '4.000'], ['4 L', 'APX-4L', '1100.00', '1350.00', '1499.00', '6.000', null]],
            $paint->variants->sortBy('name')->map(fn (ProductVariant $variant) => [
                $variant->name, $variant->sku, $variant->purchase_price, $variant->selling_price, $variant->mrp, $variant->stock_quantity, $variant->min_stock,
            ])->values()->all(),
        );

        $hammer = Product::where('name', 'Claw Hammer')->firstOrFail();
        $this->assertSame($tools->id, $hammer->category_id);
        $this->assertSame($stanley->id, $hammer->brand_id);
        $this->assertSame('SHOP-GF-RA-S1', $hammer->location_code);

        $nails = Product::where('name', 'Wire Nails')->with('variants', 'category.parent')->firstOrFail();
        $this->assertSame('Hardware > Nails', "{$nails->category->parent->name} > {$nails->category->name}");
        $this->assertEquals([90, 120, 25.5], [$nails->variants[0]->purchase_price, $nails->variants[0]->selling_price, $nails->variants[0]->stock_quantity]);

        $log = ActivityLog::latest('id')->first();
        $this->assertSame('product.imported', $log->action->value);
        $this->assertSame('Imported 3 products (4 sizes) from products.xlsx; added 4 categories and 1 brand', $log->description);
    }

    public function test_problems_are_reported_by_row_and_column(): void
    {
        $problems = $this->check($this->excel([
            $this->good(['name' => 'No Price', 'selling_price' => null]),
            $this->good(['name' => 'Odd Unit', 'unit' => 'dozenz']),
            $this->good(['name' => 'Half Hammer', 'stock' => 2.5, 'min_stock' => 'few']),
            $this->good(['name' => 'Cheap MRP', 'mrp' => 300]),
            $this->good(['name' => 'Two Rows', 'size' => 'Small']),
            $this->good(['name' => 'two rows', 'category' => 'Paints']),
            $this->good(['name' => 'Floating Shelf', 'floor' => 1, 'shelf' => 4]),
            $this->good(['name' => 'Code One', 'sku' => 'DUP-1']),
            $this->good(['name' => 'Code Two', 'sku' => 'dup-1']),
            $this->good(['name' => 'Bad Tax', 'hsn' => '12', 'gst' => 120, 'active' => 'maybe']),
            $this->good(['name' => null, 'purchase_price' => -5]),
            $this->good(['name' => 'Nowhere', 'area' => 'Basement', 'floor' => 'Roof', 'rack' => 'TOO-LONG']),
        ]))
            ->assertOk()
            // Only Code One is fine; its duplicate item code is Code Two's problem.
            ->assertJsonPath('data.ready', ['products' => 1, 'sizes' => 1])
            ->assertJsonPath('data.problem_rows', 10)
            // Both rows of Two Rows are left out, though only the second has problems.
            ->assertJsonPath('data.left_out_rows', 11)
            ->json('data.problems');

        $expected = [
            [2, 'Selling price', 'Selling price is missing.'],
            [3, 'Unit', 'Unit "dozenz" is not in the app. Use one of: kg, ltr, pc.'],
            [4, 'Opening stock', 'Opening stock must be a whole number for Piece (pc).'],
            [4, 'Minimum stock', 'Minimum stock must be a number, such as 10 or 2.5.'],
            [5, 'MRP', 'MRP cannot be lower than the selling price.'],
            [7, 'Category', 'Category differs from row 6, but every size of a product shares it.'],
            [7, 'Size or variant', 'This product has several rows, so give each one its size or variant, such as 1 L.'],
            [8, 'Rack', 'Fill in the rack too: a shelf needs its rack.'],
            [10, 'Item code (SKU)', 'Item code dup-1 is also on row 9.'],
            [11, 'HSN code', 'HSN code is 4, 6 or 8 digits.'],
            [11, 'GST rate (%)', 'GST rate is a number from 0 to 100, such as 18.'],
            [11, 'Active', 'Write Yes or No.'],
            [12, 'Product name', 'Product name is missing.'],
            [12, 'Purchase price', 'Purchase price cannot be below 0.'],
            [13, 'Shop or godown', 'Write Shop or Godown.'],
            [13, 'Floor', 'Write Ground or a floor number from 1 to 20.'],
            [13, 'Rack', 'A rack is up to 4 letters or numbers, such as 3 or A.'],
        ];

        foreach ($expected as [$row, $column, $message]) {
            $this->assertContains(['row' => $row, 'column' => $column, 'message' => $message], $problems, "Row {$row}: {$message}");
        }

        // A unit's plural or full name is understood.
        $this->check($this->excel([$this->good(['unit' => 'pcs']), $this->good(['name' => 'Rope', 'unit' => 'Kilogram'])]))
            ->assertJsonPath('data.problems', [])
            ->assertJsonPath('data.ready.products', 2);
    }

    public function test_products_already_in_the_app_are_left_out(): void
    {
        Product::factory()->create(['name' => 'Claw Hammer']);

        $this->check($this->excel([$this->good(['name' => 'CLAW HAMMER']), $this->good(['name' => 'Measuring Tape'])]))
            ->assertJsonPath('data.existing', [['rows' => [2], 'name' => 'CLAW HAMMER']])
            ->assertJsonPath('data.ready.products', 1);

        $this->import($this->excel([$this->good(), $this->good(['name' => 'Measuring Tape'])]))
            ->assertCreated()
            ->assertJsonPath('data.products', 1)
            ->assertJsonPath('data.existing', 1);

        // Importing the same file again adds nothing.
        $this->import($this->excel([$this->good(), $this->good(['name' => 'Measuring Tape'])]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file' => 'There is nothing new to import in this file.']);

        $this->assertSame(1, Product::where('name', 'Measuring Tape')->count());
    }

    public function test_with_problems_nothing_is_saved_unless_the_ready_products_are_chosen(): void
    {
        $file = fn () => $this->excel([
            $this->good(),
            $this->good(['name' => 'Spanner Set', 'size' => '6 pc']),
            $this->good(['name' => 'Spanner Set', 'size' => '12 pc', 'selling_price' => 'free']),
        ]);

        $this->import($file())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file' => 'Some rows have problems. Fix them and check the file again, or choose to import only the products that are ready.']);
        $this->assertDatabaseCount('products', 0);

        // The spanner set is left out whole, both its sizes, though only one has the problem.
        $this->import($file(), skipProblems: true)
            ->assertCreated()
            ->assertJsonPath('data.products', 1)
            ->assertJsonPath('data.left_out_rows', 2);

        $this->assertSame(['Claw Hammer'], Product::pluck('name')->all());
        $this->assertStringEndsWith('left out 2 rows with problems', ActivityLog::latest('id')->first()->description);
    }

    public function test_item_codes_already_in_use_are_problems(): void
    {
        $other = Product::factory()->create(['name' => 'Hammer Pro']);
        ProductVariant::factory()->for($other)->create(['sku' => 'HAM-500']);

        $this->check($this->excel([$this->good(['sku' => 'HAM-500'])]))
            ->assertJsonPath('data.problems', [
                ['row' => 2, 'column' => 'Item code (SKU)', 'message' => 'Item code HAM-500 already belongs to Hammer Pro.'],
            ]);
    }

    public function test_a_category_name_alone_finds_it_anywhere_unless_several_have_it(): void
    {
        $electronics = Category::factory()->create(['name' => 'Electronics']);
        $fridge = Category::factory()->create(['name' => 'Fridge', 'parent_id' => $electronics->id]);
        Category::factory()->create(['name' => 'Accessories', 'parent_id' => $electronics->id]);
        $tools = Category::factory()->create(['name' => 'Tools']);
        Category::factory()->create(['name' => 'Accessories', 'parent_id' => $tools->id]);

        $this->check($this->excel([
            $this->good(['name' => 'Kenstar Fridge', 'category' => 'fridge']),
            $this->good(['name' => 'Cable', 'category' => 'Accessories']),
            $this->good(['name' => 'Bit Set', 'category' => 'Tools > Accessories']),
        ]))
            ->assertJsonPath('data.new_categories', [])
            ->assertJsonPath('data.problems', [
                ['row' => 3, 'column' => 'Category', 'message' => 'Several categories are called Accessories. Write the full path, such as Electronics > Accessories.'],
            ]);

        $this->import($this->excel([$this->good(['name' => 'Kenstar Fridge', 'category' => 'fridge'])]))->assertCreated();
        $this->assertSame($fridge->id, Product::firstOrFail()->category_id);
    }

    public function test_the_file_itself_is_checked(): void
    {
        $this->check($this->excel([['Claw Hammer', 250]], ['Product name', 'Purchase price']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file' => 'These columns are missing: Category, Unit, Selling price, Opening stock. Download the template to see every column.']);

        $this->check($this->excel([]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file' => 'The file has no products under its headings.']);

        $this->check(UploadedFile::fake()->create('photo.pdf', 10, 'application/pdf'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file' => 'Use an Excel file (.xlsx or .xls) or a CSV file.']);

        $this->check($this->excel(array_fill(0, ProductSheet::MAX_ROWS + 1, $this->good())))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file' => 'The file has 2,001 product rows. Import up to 2,000 at a time by splitting it into smaller files.']);

        $this->post('/api/admin/products/import/check', [], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file' => 'Choose the Excel file to import.']);
    }

    public function test_a_csv_file_and_columns_in_any_order_are_read(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'import').'.csv';
        file_put_contents($path, "\u{FEFF}Selling price,Unit,Name,Category,Cost,Qty,Notes\n320,pc,Claw Hammer,Hand Tools,250,15,ignored\n");

        $this->check(new UploadedFile($path, 'products.csv', 'text/csv', null, true))
            ->assertOk()
            ->assertJsonPath('data.problems', [])
            ->assertJsonPath('data.ready', ['products' => 1, 'sizes' => 1])
            ->assertJsonPath('data.ignored_columns', ['Notes']);
    }
}
