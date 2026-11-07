<?php

namespace App\Services;

use App\Enums\ActivityAction;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Unit;
use App\Repositories\ProductRepository;
use App\Repositories\UnitRepository;
use App\Support\ActivityChanges;
use App\Support\ActivitySnapshot;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductService
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly ProductImageService $productImages,
        private readonly CategoryService $categories,
        private readonly BrandService $brands,
        private readonly UnitRepository $units,
        private readonly ProductLocationService $locations,
        private readonly ActivityLogService $activity,
        private readonly SlugService $slugs,
    ) {}

    /**
     * @param  array<string, mixed>  $filters  Validated list filters: search, category_id, brand_id, low_stock, per_page.
     * @return LengthAwarePaginator<int, Product>
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        return $this->products->paginate($this->repositoryFilters($filters), (int) ($filters['per_page'] ?? 15));
    }

    /**
     * @param  array<string, mixed>  $filters  The same filters as the list.
     * @return array{products: int, low_stock: int, cost_value: float, selling_value: float}
     */
    public function stockSummary(array $filters): array
    {
        return $this->products->stockSummary($this->repositoryFilters($filters));
    }

    public function details(Product $product): Product
    {
        return $this->products->loadDetails($product);
    }

    /**
     * Everything the product form offers: categories in tree order, brands and units.
     *
     * @return array{categories: Collection<int, Category>, brands: Collection<int, Brand>, units: Collection<int, Unit>}
     */
    public function formOptions(): array
    {
        return [
            'categories' => $this->categories->tree(),
            'brands' => $this->brands->list(),
            'units' => $this->units->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data  Validated product fields plus a "variants" list.
     *
     * @throws ValidationException
     */
    public function create(array $data): Product
    {
        $this->ensureWholeQuantities($data);
        $data = $this->withPack($data);

        $data['slug'] = $this->resolveSlug($data);
        $data = $this->locations->withCode($data);

        return DB::transaction(function () use ($data) {
            $data = $this->withNewBrand($data);
            $product = $this->products->create(Arr::except($data, 'variants'));
            $this->products->syncVariants($product, $data['variants']);

            $this->activity->record(
                ActivityAction::ProductCreated,
                "Added product {$product->name}",
                $product,
                ActivityChanges::between([], ActivitySnapshot::product($product)),
            );

            return $this->products->loadDetails($product);
        });
    }

    /**
     * @param  array<string, mixed>  $data  Validated product fields plus the full "variants" list.
     *
     * @throws ValidationException
     */
    public function update(Product $product, array $data): Product
    {
        // An existing variant's stock changes only through Stock in and stock corrections,
        // which are logged; the form sets stock only for a new variant, as its opening stock.
        $data['variants'] = array_map(
            fn (array $variant) => isset($variant['id']) ? Arr::except($variant, 'stock_quantity') : $variant,
            $data['variants'],
        );

        $this->ensureWholeQuantities($data);
        $data = $this->withPack($data);

        $data['slug'] = $this->resolveSlug($data, $product);
        $data = $this->locations->withCode($data);

        $before = ActivitySnapshot::product($product);

        return DB::transaction(function () use ($product, $data, $before) {
            $data = $this->withNewBrand($data);
            $this->products->update($product, Arr::except($data, 'variants'));
            $this->products->syncVariants($product, $data['variants']);

            $changes = ActivityChanges::between($before, ActivitySnapshot::product($product));

            // Saving without changing anything is not worth an entry.
            if ($changes) {
                $this->activity->record(
                    ActivityAction::ProductUpdated,
                    "Changed product {$product->name}: ".ActivityChanges::summary($changes),
                    $product,
                    $changes,
                );
            }

            return $this->products->loadDetails($product);
        });
    }

    public function delete(Product $product): void
    {
        $imagePaths = $this->productImages->pathsFor($product);
        $before = ActivitySnapshot::product($product);

        DB::transaction(function () use ($product, $before) {
            // The product's variants and image rows are deleted with it.
            $this->products->delete($product);

            $this->activity->record(
                ActivityAction::ProductDeleted,
                "Deleted product {$product->name}",
                $product,
                ActivityChanges::between($before, []),
            );
        });

        $this->productImages->deleteFiles($imagePaths);
    }

    /**
     * A brand typed into the product form is created on the spot and given to the product.
     * Callers run this inside the product's transaction, so a failed save leaves no brand behind.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function withNewBrand(array $data): array
    {
        $name = $data['new_brand_name'] ?? null;
        unset($data['new_brand_name']);

        if (filled($name)) {
            $data['brand_id'] = $this->brands->create(['name' => $name])->id;
        }

        return $data;
    }

    /**
     * Units such as Piece or Box are counted in whole numbers, so their stock (and the
     * minimum stock to keep) cannot be 2.5.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    private function ensureWholeQuantities(array $data): void
    {
        $unit = $this->units->find((int) $data['unit_id']);

        if (! $unit || $unit->allow_decimal) {
            return;
        }

        $errors = [];

        $fields = ['stock_quantity' => 'Stock', 'min_stock' => 'Minimum stock'];

        foreach ($data['variants'] as $index => $variant) {
            foreach ($fields as $field => $label) {
                if (! isset($variant[$field])) {
                    continue;
                }

                $quantity = (float) $variant[$field];

                if (floor($quantity) !== $quantity) {
                    $errors["variants.{$index}.{$field}"] = [
                        "{$label} must be a whole number for {$unit->name} ({$unit->short_name}).",
                    ];
                }
            }
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * A product bought in packs and sold loose, such as wire in 90 m coils sold by the metre.
     * Packs are counted whole, and a pack of a unit counted whole (Box of pieces) holds a whole
     * number of them. Without a pack, the pack size and the sizes' pack prices are cleared.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function withPack(array $data): array
    {
        if (empty($data['pack_unit_id'])) {
            $data['pack_unit_id'] = null;
            $data['pack_size'] = null;
            $data['variants'] = array_map(fn (array $variant) => [...$variant, 'pack_price' => null], $data['variants']);

            return $data;
        }

        $pack = $this->units->find((int) $data['pack_unit_id']);
        $unit = $this->units->find((int) $data['unit_id']);

        if ($pack->allow_decimal) {
            throw ValidationException::withMessages([
                'pack_unit_id' => ["{$pack->name} is not counted in whole packs. Choose a pack such as Box, Bag or Coil."],
            ]);
        }

        $size = (float) $data['pack_size'];

        if (! $unit->allow_decimal && floor($size) !== $size) {
            throw ValidationException::withMessages([
                'pack_size' => ["One {$pack->name} holds a whole number of {$unit->name} ({$unit->short_name})."],
            ]);
        }

        return $data;
    }

    /**
     * Turn the request's filters into repository filters; a category filter
     * also matches products in its subcategories.
     *
     * @param  array<string, mixed>  $filters
     * @return array{search: string|null, category_ids: list<int>|null, brand_id: int|null, low_stock: bool}
     */
    private function repositoryFilters(array $filters): array
    {
        return [
            'search' => $filters['search'] ?? null,
            'category_ids' => isset($filters['category_id']) ? $this->categories->branchIds((int) $filters['category_id']) : null,
            'brand_id' => isset($filters['brand_id']) ? (int) $filters['brand_id'] : null,
            'low_stock' => (bool) ($filters['low_stock'] ?? false),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveSlug(array $data, ?Product $product = null): string
    {
        return $this->slugs->resolve(
            $data,
            $product?->slug,
            fn (string $slug) => $this->products->slugExists($slug, $product?->id),
            'product',
        );
    }
}
