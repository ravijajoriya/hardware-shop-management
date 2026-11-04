<?php

namespace App\Repositories;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * @phpstan-type Filters array{search?: string|null, category_ids?: list<int>|null, brand_id?: int|null, low_stock?: bool}
 */
class ProductRepository
{
    /**
     * The relations every product in the list includes; only its main image is needed there.
     */
    private const LIST_RELATIONS = [
        'category:id,name',
        'brand:id,name',
        'unit:id,name,short_name,allow_decimal',
        'variants',
        'mainImage',
    ];

    /**
     * The relations a single product includes.
     */
    private const DETAIL_RELATIONS = [...self::LIST_RELATIONS, 'images'];

    /**
     * @param  Filters  $filters
     * @return LengthAwarePaginator<int, Product>
     */
    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->filtered($filters)
            ->with(self::LIST_RELATIONS)
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * How many products match the filters, how many of those are low on stock, and what
     * their stock is worth at purchase price and at selling price.
     *
     * @param  Filters  $filters
     * @return array{products: int, low_stock: int, cost_value: float, selling_value: float}
     */
    public function stockSummary(array $filters): array
    {
        $totals = ProductVariant::whereIn('product_id', $this->filtered($filters)->select('id'))
            ->selectRaw('COALESCE(SUM(stock_quantity * purchase_price), 0) AS cost_value')
            ->selectRaw('COALESCE(SUM(stock_quantity * selling_price), 0) AS selling_value')
            ->toBase()
            ->first();

        return [
            'products' => $this->filtered($filters)->count(),
            'low_stock' => $this->filtered([...$filters, 'low_stock' => true])->count(),
            'cost_value' => round((float) $totals->cost_value, 2),
            'selling_value' => round((float) $totals->selling_value, 2),
        ];
    }

    /**
     * How many products need ordering, across the whole shop.
     */
    public function lowStockCount(): int
    {
        return $this->filtered(['low_stock' => true])->count();
    }

    public function loadDetails(Product $product): Product
    {
        return $product->load(self::DETAIL_RELATIONS);
    }

    /**
     * Which of these names products already have, ignoring case: lowercase name => name.
     *
     * @param  list<string>  $names
     * @return array<string, string>
     */
    public function namesTaken(array $names): array
    {
        $taken = [];

        foreach (array_chunk(array_unique(array_map('mb_strtolower', $names)), 500) as $chunk) {
            foreach (Product::whereIn(DB::raw('LOWER(name)'), $chunk)->pluck('name') as $name) {
                $taken[mb_strtolower($name)] = $name;
            }
        }

        return $taken;
    }

    public function slugExists(string $slug, ?int $ignoreId = null): bool
    {
        return Product::where('slug', $slug)
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): Product
    {
        return Product::create($attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Product $product, array $attributes): Product
    {
        $product->update($attributes);

        return $product;
    }

    public function delete(Product $product): void
    {
        $product->delete();
    }

    /**
     * Make the product's variants match the given list: rows with an id are
     * updated, rows without one are created, and variants left out are deleted.
     *
     * @param  array<int, array<string, mixed>>  $variants
     */
    public function syncVariants(Product $product, array $variants): void
    {
        $keptIds = array_filter(array_column($variants, 'id'));

        $product->variants()->whereNotIn('id', $keptIds)->delete();

        // Clear the kept variants' SKUs first so two variants can swap SKUs
        // without tripping the unique index halfway through.
        $product->variants()->update(['sku' => null]);

        foreach ($variants as $attributes) {
            $id = $attributes['id'] ?? null;
            unset($attributes['id']);

            if ($id) {
                $product->variants()->whereKey($id)->update($attributes);
            } else {
                $product->variants()->create($attributes);
            }
        }
    }

    /**
     * @param  Filters  $filters
     * @return Builder<Product>
     */
    private function filtered(array $filters): Builder
    {
        return Product::query()
            ->when($filters['search'] ?? null, function (Builder $query, string $search) {
                $query->where(function (Builder $query) use ($search) {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('location_code', 'like', "%{$search}%")
                        ->orWhereHas('variants', fn (Builder $variants) => $variants->where('sku', 'like', "%{$search}%"));
                });
            })
            ->when($filters['category_ids'] ?? null, fn (Builder $query, array $ids) => $query->whereIn('category_id', $ids))
            ->when($filters['brand_id'] ?? null, fn (Builder $query, int $id) => $query->where('brand_id', $id))
            // As Product::isLowOnStock(): still sold, with a variant below its minimum stock.
            ->when($filters['low_stock'] ?? false, fn (Builder $query) => $query
                ->where('is_active', true)
                ->whereHas('variants', fn (Builder $variants) => $variants
                    ->whereNotNull('min_stock')
                    ->whereColumn('stock_quantity', '<', 'min_stock')));
    }
}
