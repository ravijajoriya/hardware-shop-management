<?php

namespace App\Services;

use App\Enums\ActivityAction;
use App\Enums\StorageArea;
use App\Http\Requests\Admin\StoreProductRequest;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Unit;
use App\Repositories\BrandRepository;
use App\Repositories\CategoryRepository;
use App\Repositories\ProductRepository;
use App\Repositories\ProductVariantRepository;
use App\Repositories\UnitRepository;
use App\Support\ProductSheet;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Adds products from a spreadsheet, so a shop does not have to type in its whole stock.
 *
 * A file is checked first and nothing is saved: every row is read, rows with the same product
 * name become the sizes of one product, and each problem is reported by row and column.
 * Products whose name is already in the app are left out. Importing then saves the products
 * that are ready, in one go, adding the categories and brands they name that are not in the
 * app yet. Stock is set only as a new product's opening stock; after that it changes only
 * through Stock in and stock corrections, as everywhere else.
 */
class ProductImportService
{
    /** The fields every size of a product shares, by column. */
    private const PRODUCT_FIELDS = ['category', 'brand', 'unit', 'area', 'floor', 'rack', 'shelf', 'hsn', 'gst', 'description', 'active'];

    public function __construct(
        private readonly ProductRepository $products,
        private readonly ProductVariantRepository $variants,
        private readonly CategoryRepository $categories,
        private readonly BrandRepository $brands,
        private readonly UnitRepository $units,
        private readonly ProductLocationService $locations,
        private readonly SlugService $slugs,
        private readonly ActivityLogService $activity,
    ) {}

    /**
     * The template to fill in, as the contents of an .xlsx file.
     */
    public function template(): string
    {
        return ProductSheet::template(
            $this->units->all()->map(fn (Unit $unit) => ['short_name' => $unit->short_name, 'name' => $unit->name])->all(),
            $this->categoryPaths(),
            $this->brands->all()->pluck('name')->all(),
        );
    }

    /**
     * What importing the file would do, without saving anything.
     *
     * @return array<string, mixed>
     *
     * @throws ValidationException When the file cannot be read.
     */
    public function check(UploadedFile $file): array
    {
        return $this->report($this->plan($file));
    }

    /**
     * Save the products in the file that are ready. With problems in the file, nothing is saved
     * unless $skipProblems says to leave those products out.
     *
     * @return array{products: int, sizes: int, categories: int, brands: int, existing: int, left_out_rows: int}
     *
     * @throws ValidationException
     */
    public function import(UploadedFile $file, bool $skipProblems): array
    {
        $plan = $this->plan($file);

        if ($plan['problems'] && ! $skipProblems) {
            throw ValidationException::withMessages(['file' => [
                'Some rows have problems. Fix them and check the file again, or choose to import only the products that are ready.',
            ]]);
        }

        if ($plan['ready'] === []) {
            throw ValidationException::withMessages(['file' => ['There is nothing new to import in this file.']]);
        }

        return DB::transaction(function () use ($plan, $file) {
            $categories = [];
            $brands = [];
            $sizes = 0;
            $names = [];

            foreach ($plan['ready'] as $product) {
                $category = $this->ensureCategory($product['category'], $categories);
                $brand = $product['brand'] === null ? null : $this->ensureBrand($product['brand'], $brands);

                $data = $this->locations->withCode([
                    'category_id' => $category->id,
                    'brand_id' => $brand?->id,
                    'unit_id' => $product['unit']->id,
                    'name' => $product['name'],
                    'description' => $product['description'],
                    'hsn_code' => $product['hsn'],
                    'gst_rate' => $product['gst'],
                    'is_active' => $product['active'],
                    'location_area' => $product['area']->value,
                    'location_floor' => $product['floor'],
                    'location_rack' => $product['rack'],
                    'location_shelf' => $product['shelf'],
                ]);
                $data['slug'] = $this->slugs->resolve(
                    ['name' => $product['name']],
                    null,
                    fn (string $slug) => $this->products->slugExists($slug),
                    'product',
                );

                $saved = $this->products->create($data);
                $this->products->syncVariants($saved, array_map(fn (array $size) => [
                    'name' => $size['size'],
                    'sku' => $size['sku'],
                    'purchase_price' => $size['purchase_price'],
                    'selling_price' => $size['selling_price'],
                    'mrp' => $size['mrp'],
                    'stock_quantity' => $size['stock'],
                    'min_stock' => $size['min_stock'],
                ], $product['sizes']));

                $sizes += count($product['sizes']);
                $names[] = $product['name'];
            }

            $summary = [
                'products' => count($plan['ready']),
                'sizes' => $sizes,
                'categories' => count(array_filter($categories, fn (array $entry) => $entry['new'])),
                'brands' => count(array_filter($brands, fn (array $entry) => $entry['new'])),
                'existing' => count($plan['existing']),
                'left_out_rows' => $plan['left_out_rows'],
            ];
            $listed = array_slice($names, 0, 100);

            if (count($names) > count($listed)) {
                $listed[] = 'and '.(count($names) - count($listed)).' more';
            }

            $this->activity->record(
                ActivityAction::ProductsImported,
                $this->describe($summary, $file->getClientOriginalName()),
                details: [
                    ['label' => 'File', 'value' => $file->getClientOriginalName()],
                    ['label' => 'Products', 'value' => $listed],
                    ['label' => 'New categories', 'value' => array_values(array_map(fn (array $entry) => $entry['path'], array_filter($categories, fn (array $entry) => $entry['new'])))],
                    ['label' => 'New brands', 'value' => array_values(array_map(fn (array $entry) => $entry['model']->name, array_filter($brands, fn (array $entry) => $entry['new'])))],
                ],
            );

            return $summary;
        });
    }

    /**
     * Read and check every row.
     *
     * @return array{
     *     file: string,
     *     rows: int,
     *     ignored: list<string>,
     *     problems: list<array{row: int, column: string|null, message: string}>,
     *     existing: list<array{rows: list<int>, name: string}>,
     *     ready: list<array<string, mixed>>,
     *     problem_rows: int,
     *     left_out_rows: int,
     * }
     *
     * @throws ValidationException
     */
    private function plan(UploadedFile $file): array
    {
        $sheet = ProductSheet::read($file->getRealPath(), $file->getClientOriginalExtension() ?: (string) $file->extension());

        $units = $this->unitIndex();
        $categories = $this->categoryIndex();
        $brands = $this->brands->all()->keyBy(fn (Brand $brand) => Str::lower($brand->name));

        $problems = [];
        $groups = [];

        foreach ($sheet['rows'] as $row) {
            $parsed = $this->parseRow($row['row'], $row['values'], $problems);

            if ($parsed['name'] === null) {
                continue;
            }

            $groups[Str::lower($parsed['name'])][] = $parsed;
        }

        $products = [];

        foreach ($groups as $rows) {
            $products[] = $this->product($rows, $units, $categories, $problems);
        }

        $this->checkItemCodes($products, $problems);

        $taken = $this->products->namesTaken(array_map(fn (array $product) => $product['name'], $products));
        $problemRows = array_flip(array_column($problems, 'row'));
        $existing = [];
        $ready = [];
        // A product with a problem on any of its rows is left out whole, all its sizes with it;
        // so is a row without a product name.
        $leftOut = array_diff_key($problemRows, array_flip(array_merge(...array_column($products, 'rows') ?: [[]])));

        foreach ($products as $product) {
            if (isset($taken[Str::lower($product['name'])])) {
                $existing[] = ['rows' => $product['rows'], 'name' => $product['name']];
            } elseif (array_intersect_key(array_flip($product['rows']), $problemRows)) {
                $leftOut += array_flip($product['rows']);
            } else {
                $product['brand_is_new'] = $product['brand'] !== null && ! $brands->has(Str::lower($product['brand']));
                $ready[] = $product;
            }
        }

        usort($problems, fn (array $a, array $b) => $a['row'] <=> $b['row']);

        return [
            'file' => $file->getClientOriginalName(),
            'rows' => count($sheet['rows']),
            'ignored' => $sheet['ignored'],
            'problems' => $problems,
            'existing' => $existing,
            'ready' => $ready,
            'problem_rows' => count($problemRows),
            'left_out_rows' => count($leftOut),
        ];
    }

    /**
     * What a check tells the shopkeeper.
     *
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    private function report(array $plan): array
    {
        $newCategories = [];
        $newBrands = [];

        foreach ($plan['ready'] as $product) {
            foreach ($product['category']['new'] as $path) {
                $newCategories[Str::lower($path)] = $path;
            }

            if ($product['brand_is_new']) {
                $newBrands[Str::lower($product['brand'])] = $product['brand'];
            }
        }

        return [
            'file' => $plan['file'],
            'rows' => $plan['rows'],
            'ready' => [
                'products' => count($plan['ready']),
                'sizes' => array_sum(array_map(fn (array $product) => count($product['sizes']), $plan['ready'])),
            ],
            'existing' => $plan['existing'],
            'problems' => $plan['problems'],
            'problem_rows' => $plan['problem_rows'],
            'left_out_rows' => $plan['left_out_rows'],
            'new_categories' => array_values($newCategories),
            'new_brands' => array_values($newBrands),
            'ignored_columns' => $plan['ignored'],
        ];
    }

    /**
     * One row's values, cleaned up. Problems with fields of its own (not those every size of a
     * product shares) are added to $problems.
     *
     * @param  array<string, mixed>  $values
     * @param  list<array{row: int, column: string|null, message: string}>  $problems
     * @return array<string, mixed>
     */
    private function parseRow(int $row, array $values, array &$problems): array
    {
        $problem = function (string $key, string $message) use ($row, &$problems) {
            $problems[] = ['row' => $row, 'column' => ProductSheet::COLUMNS[$key]['heading'], 'message' => $message];
        };

        $name = $this->text($values['name'] ?? null);

        if ($name === null) {
            $problem('name', 'Product name is missing.');
        } elseif (mb_strlen($name) > 255) {
            $problem('name', 'Product name can be up to 255 characters.');
        }

        $size = $this->text($values['size'] ?? null);

        if ($size !== null && mb_strlen($size) > 100) {
            $problem('size', 'Size or variant can be up to 100 characters.');
        }

        $sku = $this->text($values['sku'] ?? null);

        if ($sku !== null && mb_strlen($sku) > 50) {
            $problem('sku', 'Item code can be up to 50 characters.');
        }

        $parsed = [
            'row' => $row,
            'name' => $name,
            'size' => $size,
            'sku' => $sku,
            'purchase_price' => $this->amount($values, 'purchase_price', true, $problem),
            'selling_price' => $this->amount($values, 'selling_price', true, $problem),
            'mrp' => $this->amount($values, 'mrp', false, $problem),
            'stock' => $this->quantity($values, 'stock', true, $problem),
            'min_stock' => $this->quantity($values, 'min_stock', false, $problem),
        ];

        if ($parsed['mrp'] !== null && $parsed['selling_price'] !== null && $parsed['mrp'] < $parsed['selling_price']) {
            $problem('mrp', 'MRP cannot be lower than the selling price.');
        }

        foreach (self::PRODUCT_FIELDS as $key) {
            $parsed['raw'][$key] = $this->text($values[$key] ?? null);
        }

        return $parsed;
    }

    /**
     * Turn the rows sharing a product name into one product with its sizes, checking what the
     * sizes must share and what each needs.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, Unit>  $units
     * @param  array<string, mixed>  $categories
     * @param  list<array{row: int, column: string|null, message: string}>  $problems
     * @return array<string, mixed>
     */
    private function product(array $rows, array $units, array $categories, array &$problems): array
    {
        $first = $rows[0]['row'];
        $problem = function (int $row, string $key, string $message) use (&$problems) {
            $problems[] = ['row' => $row, 'column' => ProductSheet::COLUMNS[$key]['heading'], 'message' => $message];
        };

        // Each shared field is written once, usually on the first row; another row that gives
        // it differently is a mistake.
        $shared = [];

        foreach (self::PRODUCT_FIELDS as $key) {
            $shared[$key] = null;

            foreach ($rows as $row) {
                $value = $row['raw'][$key];

                if ($value === null) {
                    continue;
                }

                if ($shared[$key] === null) {
                    $shared[$key] = ['value' => $value, 'row' => $row['row']];
                } elseif (Str::lower($value) !== Str::lower($shared[$key]['value'])) {
                    $problem($row['row'], $key, ProductSheet::COLUMNS[$key]['heading']." differs from row {$shared[$key]['row']}, but every size of a product shares it.");
                }
            }
        }

        $value = fn (string $key) => $shared[$key]['value'] ?? null;
        $rowOf = fn (string $key) => $shared[$key]['row'] ?? $first;

        $unit = $this->unit($value('unit'), $units);

        if ($value('unit') === null) {
            $problem($first, 'unit', 'Unit is missing.');
        } elseif ($unit === null) {
            $problem($rowOf('unit'), 'unit', "Unit \"{$value('unit')}\" is not in the app. Use one of: ".implode(', ', array_unique(array_map(fn (Unit $unit) => $unit->short_name, $units))).'.');
        }

        $category = $this->category($value('category'), $categories);

        if ($value('category') === null) {
            $problem($first, 'category', 'Category is missing.');
        } elseif (is_string($category)) {
            $problem($rowOf('category'), 'category', $category);
        }

        $brand = $value('brand');

        if ($brand !== null && mb_strlen($brand) > 255) {
            $problem($rowOf('brand'), 'brand', 'Brand can be up to 255 characters.');
        }

        $location = $this->location($value('area'), $value('floor'), $value('rack'), $value('shelf'), $rowOf, $problem);

        $hsn = $value('hsn');

        if ($hsn !== null && ! preg_match('/^(\d{4}|\d{6}|\d{8})$/', $hsn)) {
            $problem($rowOf('hsn'), 'hsn', 'HSN code is 4, 6 or 8 digits.');
        }

        $gst = null;

        if ($value('gst') !== null) {
            $gst = $this->number(rtrim($value('gst'), '% '));

            if ($gst === null || $gst < 0 || $gst > 100) {
                $problem($rowOf('gst'), 'gst', 'GST rate is a number from 0 to 100, such as 18.');
                $gst = null;
            } else {
                $gst = round($gst, 2);
            }
        }

        $description = $value('description');

        if ($description !== null && mb_strlen($description) > 2000) {
            $problem($rowOf('description'), 'description', 'Description can be up to 2,000 characters.');
        }

        $active = true;

        if ($value('active') !== null) {
            $answer = Str::lower($value('active'));

            if (in_array($answer, ['yes', 'y', 'true', '1', 'active'], true)) {
                $active = true;
            } elseif (in_array($answer, ['no', 'n', 'false', '0', 'inactive'], true)) {
                $active = false;
            } else {
                $problem($rowOf('active'), 'active', 'Write Yes or No.');
            }
        }

        // Several rows are several sizes, and each needs a name of its own.
        $sizes = [];

        foreach ($rows as $row) {
            if (count($rows) > 1 && $row['size'] === null) {
                $problem($row['row'], 'size', 'This product has several rows, so give each one its size or variant, such as 1 L.');
            } elseif ($row['size'] !== null && isset($sizes[Str::lower($row['size'])])) {
                $problem($row['row'], 'size', "{$row['size']} is also on row {$sizes[Str::lower($row['size'])]} for this product.");
            }

            if ($row['size'] !== null) {
                $sizes[Str::lower($row['size'])] ??= $row['row'];
            }

            // Units such as Piece or Box are counted in whole numbers.
            if ($unit && ! $unit->allow_decimal) {
                foreach (['stock' => 'Opening stock', 'min_stock' => 'Minimum stock'] as $key => $label) {
                    if ($row[$key] !== null && floor($row[$key]) != $row[$key]) {
                        $problem($row['row'], $key, "{$label} must be a whole number for {$unit->name} ({$unit->short_name}).");
                    }
                }
            }
        }

        return [
            'name' => $rows[0]['name'],
            'rows' => array_column($rows, 'row'),
            'unit' => $unit,
            'category' => is_array($category) ? $category : ['parent' => null, 'new' => [], 'existing' => null, 'parts' => []],
            'brand' => $brand,
            'description' => $description,
            'hsn' => $hsn,
            'gst' => $gst,
            'active' => $active,
            ...$location,
            'sizes' => $rows,
        ];
    }

    /**
     * Where the product is kept, checked part by part: each part needs the one before it.
     *
     * @param  callable(string): int  $rowOf
     * @param  callable(int, string, string): void  $problem
     * @return array{area: StorageArea, floor: int|null, rack: string|null, shelf: int|null}
     */
    private function location(?string $area, ?string $floor, ?string $rack, ?string $shelf, callable $rowOf, callable $problem): array
    {
        $place = StorageArea::Shop;

        if ($area !== null) {
            $place = match (Str::lower($area)) {
                'shop', 'store' => StorageArea::Shop,
                'godown', 'go down', 'warehouse' => StorageArea::Godown,
                default => null,
            };

            if ($place === null) {
                $problem($rowOf('area'), 'area', 'Write Shop or Godown.');
                $place = StorageArea::Shop;
            }
        }

        $floorNumber = null;

        if ($floor !== null) {
            $text = Str::lower($floor);

            if (in_array($text, ['ground', 'ground floor', 'g', 'gf', '0'], true)) {
                $floorNumber = 0;
            } elseif (preg_match('/^(\d+)\s*(st|nd|rd|th)?\s*(f|floor)?$/', $text, $match) && (int) $match[1] <= ProductLocationService::MAX_FLOOR) {
                $floorNumber = (int) $match[1];
            } else {
                $problem($rowOf('floor'), 'floor', 'Write Ground or a floor number from 1 to '.ProductLocationService::MAX_FLOOR.'.');
            }
        }

        $rackCode = null;

        if ($rack !== null) {
            $rackCode = Str::upper($rack);

            if (! preg_match('/^[A-Z0-9]{1,4}$/', $rackCode)) {
                $problem($rowOf('rack'), 'rack', 'A rack is up to 4 letters or numbers, such as 3 or A.');
                $rackCode = null;
            } elseif ($floor === null) {
                $problem($rowOf('rack'), 'floor', 'Fill in the floor too: a rack needs its floor.');
            }
        }

        $shelfNumber = null;

        if ($shelf !== null) {
            if (ctype_digit($shelf) && (int) $shelf >= 1 && (int) $shelf <= ProductLocationService::MAX_SHELF) {
                $shelfNumber = (int) $shelf;

                if ($rack === null) {
                    $problem($rowOf('shelf'), 'rack', 'Fill in the rack too: a shelf needs its rack.');
                }
            } else {
                $problem($rowOf('shelf'), 'shelf', 'A shelf is a number from 1 to '.ProductLocationService::MAX_SHELF.'.');
            }
        }

        return ['area' => $place, 'floor' => $floorNumber, 'rack' => $rackCode, 'shelf' => $shelfNumber];
    }

    /**
     * Item codes must differ from each other and from those already in the app.
     *
     * @param  list<array<string, mixed>>  $products
     * @param  list<array{row: int, column: string|null, message: string}>  $problems
     */
    private function checkItemCodes(array $products, array &$problems): void
    {
        $seen = [];
        $codes = [];

        foreach ($products as $product) {
            foreach ($product['sizes'] as $size) {
                if ($size['sku'] === null) {
                    continue;
                }

                $key = Str::lower($size['sku']);

                if (isset($seen[$key])) {
                    $problems[] = ['row' => $size['row'], 'column' => ProductSheet::COLUMNS['sku']['heading'], 'message' => "Item code {$size['sku']} is also on row {$seen[$key]}."];
                } else {
                    $seen[$key] = $size['row'];
                    $codes[$key] = $size;
                }
            }
        }

        foreach ($this->variants->productNamesBySku(array_column($codes, 'sku')) as $sku => $productName) {
            $size = $codes[Str::lower($sku)] ?? null;

            if ($size && Str::lower($productName) !== Str::lower($size['name'])) {
                $problems[] = ['row' => $size['row'], 'column' => ProductSheet::COLUMNS['sku']['heading'], 'message' => "Item code {$sku} already belongs to {$productName}."];
            }
        }
    }

    /**
     * A category written in the file: the one in the app it means, or the parts to add under
     * the deepest one that exists. A message when a bare name could mean several categories.
     *
     * @param  array<string, mixed>  $index
     * @return array{parent: Category|null, new: list<string>, existing: Category|null, parts: list<string>}|string|null
     */
    private function category(?string $written, array $index): array|string|null
    {
        if ($written === null) {
            return null;
        }

        $parts = array_values(array_filter(array_map('trim', explode('>', $written)), fn (string $part) => $part !== ''));

        foreach ($parts as $part) {
            if (mb_strlen($part) > 255) {
                return 'A category name can be up to 255 characters.';
            }
        }

        // A bare name means that category wherever it is in the tree, when only one has it.
        if (count($parts) === 1) {
            $matches = $index['byName'][Str::lower($parts[0])] ?? [];

            if (count($matches) > 1) {
                return "Several categories are called {$parts[0]}. Write the full path, such as ".$this->path($matches[0], $index).'.';
            }

            if (count($matches) === 1) {
                return ['parent' => null, 'new' => [], 'existing' => $matches[0], 'parts' => $parts];
            }
        }

        $parent = null;
        $new = [];

        foreach ($parts as $position => $part) {
            $child = $index['children'][$parent?->id ?? 0][Str::lower($part)] ?? null;

            if ($child === null) {
                foreach (array_slice($parts, $position) as $offset => $missing) {
                    $new[] = implode(' > ', array_slice($parts, 0, $position + $offset + 1));
                }

                return ['parent' => $parent, 'new' => $new, 'existing' => null, 'parts' => $parts];
            }

            $parent = $child;
        }

        return ['parent' => null, 'new' => [], 'existing' => $parent, 'parts' => $parts];
    }

    /**
     * The category a product goes in, adding the missing parts of its path. $made remembers
     * what was added, so the products after it find it.
     *
     * @param  array{parent: Category|null, new: list<string>, existing: Category|null, parts: list<string>}  $category
     * @param  array<string, array{model: Category, path: string, new: bool}>  $made
     */
    private function ensureCategory(array $category, array &$made): Category
    {
        if ($category['existing']) {
            return $category['existing'];
        }

        $parent = $category['parent'];
        $depth = count($category['parts']) - count($category['new']);

        foreach (array_slice($category['parts'], $depth) as $offset => $name) {
            $path = implode(' > ', array_slice($category['parts'], 0, $depth + $offset + 1));
            $key = Str::lower($path);

            if (! isset($made[$key])) {
                $model = $this->categories->create([
                    'parent_id' => $parent?->id,
                    'name' => $name,
                    'slug' => $this->slugs->resolve(['name' => $name], null, fn (string $slug) => $this->categories->slugExists($slug), 'category'),
                    'is_active' => true,
                ]);
                $made[$key] = ['model' => $model, 'path' => $path, 'new' => true];
            }

            $parent = $made[$key]['model'];
        }

        return $parent;
    }

    /**
     * The brand a product has, adding it when it is not in the app.
     *
     * @param  array<string, array{model: Brand, new: bool}>  $made
     */
    private function ensureBrand(string $name, array &$made): Brand
    {
        $key = Str::lower($name);

        if (! isset($made[$key])) {
            $existing = $this->brands->all()->first(fn (Brand $brand) => Str::lower($brand->name) === $key);

            $made[$key] = $existing
                ? ['model' => $existing, 'new' => false]
                : ['model' => $this->brands->create([
                    'name' => $name,
                    'slug' => $this->slugs->resolve(['name' => $name], null, fn (string $slug) => $this->brands->slugExists($slug), 'brand'),
                    'is_active' => true,
                ]), 'new' => true];
        }

        return $made[$key]['model'];
    }

    /**
     * Units by short name and full name, any case; "pcs" finds "pc".
     *
     * @return array<string, Unit>
     */
    private function unitIndex(): array
    {
        $index = [];

        foreach ($this->units->all() as $unit) {
            $index[Str::lower($unit->short_name)] = $unit;
            $index[Str::lower($unit->name)] = $unit;
        }

        return $index;
    }

    /**
     * @param  array<string, Unit>  $units
     */
    private function unit(?string $written, array $units): ?Unit
    {
        if ($written === null) {
            return null;
        }

        $key = Str::lower($written);

        return $units[$key] ?? $units[rtrim($key, 's')] ?? $units[rtrim(rtrim($key, '.'), 's')] ?? null;
    }

    /**
     * The categories in the app, by parent and by name.
     *
     * @return array{children: array<int, array<string, Category>>, byName: array<string, list<Category>>, byId: array<int, Category>}
     */
    private function categoryIndex(): array
    {
        $index = ['children' => [], 'byName' => [], 'byId' => []];

        foreach ($this->categories->all() as $category) {
            $index['children'][$category->parent_id ?? 0][Str::lower($category->name)] = $category;
            $index['byName'][Str::lower($category->name)][] = $category;
            $index['byId'][$category->id] = $category;
        }

        return $index;
    }

    /**
     * A category's full path, such as Paints > Exterior.
     *
     * @param  array<string, mixed>  $index
     */
    private function path(Category $category, array $index): string
    {
        $names = [$category->name];

        for ($parent = $index['byId'][$category->parent_id] ?? null; $parent; $parent = $index['byId'][$parent->parent_id] ?? null) {
            array_unshift($names, $parent->name);
        }

        return implode(' > ', $names);
    }

    /**
     * Every category's full path, in alphabetical order, for the template's help.
     *
     * @return list<string>
     */
    private function categoryPaths(): array
    {
        $index = $this->categoryIndex();

        return collect($index['byId'])
            ->map(fn (Category $category) => $this->path($category, $index))
            ->sort(SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }

    /**
     * A price in rupees, up to two decimals.
     *
     * @param  array<string, mixed>  $values
     * @param  callable(string, string): void  $problem
     */
    private function amount(array $values, string $key, bool $needed, callable $problem): ?float
    {
        $label = ProductSheet::COLUMNS[$key]['heading'];
        $text = $this->text($values[$key] ?? null);

        if ($text === null) {
            if ($needed) {
                $problem($key, "{$label} is missing.");
            }

            return null;
        }

        $number = $this->number($text);

        if ($number === null) {
            $problem($key, "{$label} must be a number, such as 250 or 99.50.");
        } elseif ($number < 0) {
            $problem($key, "{$label} cannot be below 0.");
        } elseif ($number > StoreProductRequest::MAX_PRICE) {
            $problem($key, "{$label} is too large.");
        } else {
            return round($number, 2);
        }

        return null;
    }

    /**
     * A quantity in the product's unit, up to three decimals.
     *
     * @param  array<string, mixed>  $values
     * @param  callable(string, string): void  $problem
     */
    private function quantity(array $values, string $key, bool $needed, callable $problem): ?float
    {
        $label = ProductSheet::COLUMNS[$key]['heading'];
        $text = $this->text($values[$key] ?? null);

        if ($text === null) {
            if ($needed) {
                $problem($key, "{$label} is missing. Write 0 if you have none.");
            }

            return null;
        }

        $number = $this->number($text);

        if ($number === null) {
            $problem($key, "{$label} must be a number, such as 10 or 2.5.");
        } elseif ($number < 0) {
            $problem($key, "{$label} cannot be below 0.");
        } elseif ($number > StoreProductRequest::MAX_STOCK) {
            $problem($key, "{$label} is too large.");
        } else {
            return round($number, 3);
        }

        return null;
    }

    /**
     * A number written in a cell, allowing "₹1,250.50" and "Rs. 300".
     */
    private function number(string $text): ?float
    {
        $clean = preg_replace('/^(₹|rs\.?|inr)\s*/i', '', str_replace([',', ' '], '', $text)) ?? '';

        return is_numeric($clean) ? (float) $clean : null;
    }

    /**
     * A cell as trimmed text, null when blank. Whole numbers lose their ".0", so a code typed
     * as 3209 stays 3209.
     */
    private function text(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_float($value)) {
            $value = floor($value) == $value && abs($value) < 1e15 ? (string) (int) $value : (string) $value;
        }

        $text = trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '');

        return $text === '' ? null : $text;
    }

    /**
     * @param  array{products: int, sizes: int, categories: int, brands: int, existing: int, left_out_rows: int}  $summary
     */
    private function describe(array $summary, string $file): string
    {
        $text = 'Imported '.$this->count($summary['products'], 'product');

        if ($summary['sizes'] > $summary['products']) {
            $text .= ' ('.$this->count($summary['sizes'], 'size').')';
        }

        $text .= " from {$file}";

        $added = array_filter([
            $summary['categories'] ? $this->count($summary['categories'], 'category', 'categories') : null,
            $summary['brands'] ? $this->count($summary['brands'], 'brand') : null,
        ]);

        if ($added) {
            $text .= '; added '.implode(' and ', $added);
        }

        $left = array_filter([
            $summary['existing'] ? "{$summary['existing']} already in the app" : null,
            $summary['left_out_rows'] ? $this->count($summary['left_out_rows'], 'row').' with problems' : null,
        ]);

        if ($left) {
            $text .= '; left out '.implode(' and ', $left);
        }

        return $text;
    }

    private function count(int $count, string $one, ?string $many = null): string
    {
        return $count.' '.($count === 1 ? $one : ($many ?? "{$one}s"));
    }
}
