<?php

namespace App\Repositories;

use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Collection;

class ProductVariantRepository
{
    /**
     * The sizes being billed or stocked, keyed by id, with the product and unit each belongs to.
     *
     * @param  list<int>  $ids
     * @return Collection<int, ProductVariant>
     */
    public function findWithUnits(array $ids): Collection
    {
        return ProductVariant::with(['product:id,name,unit_id', 'product.unit:id,name,short_name,allow_decimal'])
            ->whereKey($ids)
            ->get()
            ->keyBy('id');
    }

    /**
     * The size read afresh and locked until the transaction ends, so two changes to its
     * stock at once cannot overwrite each other.
     */
    /**
     * The products that already use these item codes, ignoring case: item code => product name.
     *
     * @param  list<string>  $skus
     * @return array<string, string>
     */
    public function productNamesBySku(array $skus): array
    {
        $found = [];

        foreach (array_chunk(array_unique($skus), 500) as $chunk) {
            ProductVariant::with('product:id,name')
                ->whereIn('sku', $chunk)
                ->get(['id', 'product_id', 'sku'])
                ->each(function (ProductVariant $variant) use (&$found) {
                    $found[$variant->sku] = $variant->product->name;
                });
        }

        return $found;
    }

    public function lockForStock(ProductVariant $variant): ProductVariant
    {
        return ProductVariant::whereKey($variant->getKey())->lockForUpdate()->firstOrFail();
    }

    /**
     * Stock is allowed to go below zero, since it is counted by hand and may already be off.
     */
    public function reduceStock(ProductVariant $variant, float $quantity): void
    {
        ProductVariant::whereKey($variant->id)->decrement('stock_quantity', $quantity);
    }

    /**
     * Put items back on the shelf, such as when they are returned or a bill is cancelled.
     */
    public function returnStock(ProductVariant $variant, float $quantity): void
    {
        ProductVariant::whereKey($variant->id)->increment('stock_quantity', $quantity);
    }

    public function updateStock(ProductVariant $variant, float $stock, ?float $purchasePrice): void
    {
        $variant->update(['stock_quantity' => $stock, 'purchase_price' => $purchasePrice]);
    }

    public function setStock(ProductVariant $variant, float $stock): void
    {
        $variant->update(['stock_quantity' => $stock]);
    }
}
