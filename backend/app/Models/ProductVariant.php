<?php

namespace App\Models;

use Database\Factories\ProductVariantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['name', 'sku', 'purchase_price', 'selling_price', 'mrp', 'stock_quantity'])]
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
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'purchase_price' => 'decimal:2',
            'selling_price' => 'decimal:2',
            'mrp' => 'decimal:2',
            'stock_quantity' => 'decimal:3',
        ];
    }
}
