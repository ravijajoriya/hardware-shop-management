<?php

namespace App\Models;

use Database\Factories\ProductVariantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'sku', 'purchase_price', 'selling_price', 'pack_price', 'mrp', 'stock_quantity', 'min_stock'])]
class ProductVariant extends Model
{
    /** @use HasFactory<ProductVariantFactory> */
    use HasFactory;

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'stock_quantity' => 0,
    ];

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return HasMany<StockEntryItem, $this>
     */
    public function stockEntryItems(): HasMany
    {
        return $this->hasMany(StockEntryItem::class);
    }

    /**
     * @return HasMany<StockCorrection, $this>
     */
    public function stockCorrections(): HasMany
    {
        return $this->hasMany(StockCorrection::class);
    }

    /**
     * Whether the stock has fallen below the minimum the shop wants to keep. A variant
     * without a minimum is never low.
     */
    public function isLowOnStock(): bool
    {
        return $this->min_stock !== null && (float) $this->stock_quantity < (float) $this->min_stock;
    }

    /**
     * What this variant's stock cost to buy.
     */
    public function costValue(): float
    {
        return round((float) $this->stock_quantity * (float) $this->purchase_price, 2);
    }

    /**
     * What this variant's stock would sell for.
     */
    public function sellingValue(): float
    {
        return round((float) $this->stock_quantity * (float) $this->selling_price, 2);
    }

    /**
     * What a full pack sells for: its own price, or else the loose price times the pack size.
     * Null for a product without a pack.
     */
    public function fullPackPrice(Product $product): ?float
    {
        $size = $product->packSize();

        if ($size === null) {
            return null;
        }

        return $this->pack_price !== null
            ? (float) $this->pack_price
            : round((float) $this->selling_price * $size, 2);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // Per loose unit, with four decimals so a pack's price divides back exactly.
            'purchase_price' => 'decimal:4',
            'selling_price' => 'decimal:2',
            'pack_price' => 'decimal:2',
            'mrp' => 'decimal:2',
            'stock_quantity' => 'decimal:3',
            'min_stock' => 'decimal:3',
        ];
    }
}
