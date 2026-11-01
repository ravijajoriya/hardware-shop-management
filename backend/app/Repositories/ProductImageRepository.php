<?php

namespace App\Repositories;

use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class ProductImageRepository
{
    /**
     * The product's images in display order.
     *
     * @return Collection<int, ProductImage>
     */
    public function allFor(Product $product): Collection
    {
        return $product->images()->get();
    }

    public function countFor(Product $product): int
    {
        return $this->forProduct($product)->count();
    }

    /**
     * @return list<int>
     */
    public function idsFor(Product $product): array
    {
        return $this->forProduct($product)->pluck('id')->all();
    }

    /**
     * @return list<string>
     */
    public function pathsFor(Product $product): array
    {
        return $this->forProduct($product)->pluck('path')->all();
    }

    /**
     * The position that puts a new image after all existing ones.
     */
    public function nextSortOrder(Product $product): int
    {
        $last = $this->forProduct($product)->max('sort_order');

        return $last === null ? 0 : $last + 1;
    }

    public function create(Product $product, string $path, int $sortOrder): ProductImage
    {
        return $product->images()->create(['path' => $path, 'sort_order' => $sortOrder]);
    }

    /**
     * @param  list<int>  $ids  The product's image ids in their new order.
     */
    public function reorder(Product $product, array $ids): void
    {
        foreach ($ids as $position => $id) {
            $this->forProduct($product)->whereKey($id)->update(['sort_order' => $position]);
        }
    }

    public function delete(ProductImage $image): void
    {
        $image->delete();
    }

    /**
     * An unordered query, so aggregates like count and max stay valid SQL.
     *
     * @return Builder<ProductImage>
     */
    private function forProduct(Product $product): Builder
    {
        return ProductImage::where('product_id', $product->id);
    }
}
