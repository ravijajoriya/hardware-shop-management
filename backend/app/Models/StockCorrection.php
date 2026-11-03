<?php

namespace App\Models;

use App\Enums\StockCorrectionReason;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A stock count set by hand, with the count before it and why it changed.
 */
#[Fillable([
    'product_id', 'product_variant_id', 'product_name', 'variant_name', 'unit',
    'stock_before', 'stock_after', 'reason', 'note', 'created_by',
])]
class StockCorrection extends Model
{
    /**
     * The product corrected, while it still exists.
     *
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * The size corrected, while it still exists.
     *
     * @return BelongsTo<ProductVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /**
     * The admin who made the correction.
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'stock_before' => 'decimal:3',
            'stock_after' => 'decimal:3',
            'reason' => StockCorrectionReason::class,
        ];
    }
}
