<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'product_id', 'product_variant_id', 'product_name', 'variant_name', 'unit',
    'quantity', 'purchase_price', 'line_total', 'cost_before', 'cost_after',
])]
class StockEntryItem extends Model
{
    /**
     * @return BelongsTo<StockEntry, $this>
     */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(StockEntry::class, 'stock_entry_id');
    }

    /**
     * The product that came in, while it still exists.
     *
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * The size that came in, while it still exists.
     *
     * @return BelongsTo<ProductVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'purchase_price' => 'decimal:2',
            'line_total' => 'decimal:2',
            'cost_before' => 'decimal:2',
            'cost_after' => 'decimal:2',
        ];
    }
}
