<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'bill_item_id', 'product_id', 'product_variant_id', 'product_name', 'variant_name', 'unit',
    'quantity', 'price', 'line_total', 'cost_price',
])]
class BillReturnItem extends Model
{
    /**
     * @return BelongsTo<BillReturn, $this>
     */
    public function billReturn(): BelongsTo
    {
        return $this->belongsTo(BillReturn::class);
    }

    /**
     * The bill line the items came from.
     *
     * @return BelongsTo<BillItem, $this>
     */
    public function billItem(): BelongsTo
    {
        return $this->belongsTo(BillItem::class);
    }

    /**
     * The size returned, while it still exists.
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
            'price' => 'decimal:2',
            'line_total' => 'decimal:2',
            'cost_price' => 'decimal:2',
        ];
    }
}
