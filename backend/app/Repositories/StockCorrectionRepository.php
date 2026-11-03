<?php

namespace App\Repositories;

use App\Models\Product;
use App\Models\StockCorrection;
use Illuminate\Database\Eloquent\Collection;

class StockCorrectionRepository
{
    public function create(array $attributes): StockCorrection
    {
        return StockCorrection::create($attributes);
    }

    /**
     * The product's stock corrections, newest first.
     *
     * @return Collection<int, StockCorrection>
     */
    public function forProduct(Product $product, int $limit): Collection
    {
        return $product->stockCorrections()
            ->with('creator:id,name')
            ->latest('id')
            ->limit($limit)
            ->get();
    }
}
