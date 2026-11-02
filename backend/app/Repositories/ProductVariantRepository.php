<?php

namespace App\Repositories;

use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Collection;

class ProductVariantRepository
{
    /**
     * The sizes being billed, keyed by id, with the product and unit each belongs to.
     *
     * @param  list<int>  $ids
     * @return Collection<int, ProductVariant>
     */
    public function findForBilling(array $ids): Collection
    {
        return ProductVariant::with(['product:id,name,unit_id', 'product.unit:id,name,short_name,allow_decimal'])
            ->whereKey($ids)
            ->get()
            ->keyBy('id');
    }

    /**
     * Stock is allowed to go below zero, since it is counted by hand and may already be off.
     */
    public function reduceStock(ProductVariant $variant, float $quantity): void
    {
        ProductVariant::whereKey($variant->id)->decrement('stock_quantity', $quantity);
    }
}
