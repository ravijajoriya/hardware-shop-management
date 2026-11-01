<?php

namespace App\Services;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Unit;
use App\Repositories\ProductRepository;
use App\Repositories\UnitRepository;
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
        private readonly SlugService $slugs,
    ) {}

    /**
     * @param  array<string, mixed>  $filters  Validated list filters: search, category_id, brand_id, per_page.
     * @return LengthAwarePaginator<int, Product>
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        return $this->products->paginate($this->repositoryFilters($filters), (int) ($filters['per_page'] ?? 15));
    }

    /**
     * @param  array<string, mixed>  $filters  The same filters as the list.
     * @return array{products: int, cost_value: float, selling_value: float}
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

        $data['slug'] = $this->resolveSlug($data);
        $data = $this->locations->withCode($data);

        return DB::transaction(function () use ($data) {
            $data = $this->withNewBrand($data);
            $product = $this->products->create(Arr::except($data, 'variants'));
            $this->products->syncVariants($product, $data['variants']);

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
        $this->ensureWholeQuantities($data);

        $data['slug'] = $this->resolveSlug($data, $product);
        $data = $this->locations->withCode($data);

        return DB::transaction(function () use ($product, $data) {
            $data = $this->withNewBrand($data);
            $this->products->update($product, Arr::except($data, 'variants'));
            $this->products->syncVariants($product, $data['variants']);

            return $this->products->loadDetails($product);
        });
    }

    public function delete(Product $product): void
    {
        $imagePaths = $this->productImages->pathsFor($product);

        // The product's variants and image rows are deleted with it.
        $this->products->delete($product);

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
     * Units such as Piece or Box are counted in whole numbers, so their stock cannot be 2.5.
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

        foreach ($data['variants'] as $index => $variant) {
            $quantity = (float) $variant['stock_quantity'];

            if (floor($quantity) !== $quantity) {
                $errors["variants.{$index}.stock_quantity"] = [
                    "Stock must be a whole number for {$unit->name} ({$unit->short_name}).",
                ];
            }
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Turn the request's filters into repository filters; a category filter
     * also matches products in its subcategories.
     *
     * @param  array<string, mixed>  $filters
     * @return array{search: string|null, category_ids: list<int>|null, brand_id: int|null}
     */
    private function repositoryFilters(array $filters): array
    {
        return [
            'search' => $filters['search'] ?? null,
            'category_ids' => isset($filters['category_id']) ? $this->categories->branchIds((int) $filters['category_id']) : null,
            'brand_id' => isset($filters['brand_id']) ? (int) $filters['brand_id'] : null,
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
