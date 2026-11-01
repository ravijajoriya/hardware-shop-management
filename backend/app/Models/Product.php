<?php

namespace App\Models;

use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property-read ProductImage|null $mainImage
 */
#[Fillable([
    'category_id', 'brand_id', 'unit_id', 'name', 'slug', 'description',
    'hsn_code', 'gst_rate', 'is_active',
])]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
    ];

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return BelongsTo<Brand, $this>
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * @return BelongsTo<Unit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /**
     * The sizes or colours this product is sold in; a simple product has one.
     *
     * @return HasMany<ProductVariant, $this>
     */
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->orderBy('id');
    }

    /**
     * The product's photos in display order; the first is its main image.
     *
     * @return HasMany<ProductImage, $this>
     */
    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * The photo shown first, such as the thumbnail in the product list.
     *
     * @return HasOne<ProductImage, $this>
     */
    public function mainImage(): HasOne
    {
        return $this->hasOne(ProductImage::class)->ofMany(['sort_order' => 'min', 'id' => 'min']);
    }

    /**
     * Stock across all variants, in the product's unit.
     */
    public function totalStock(): float
    {
        return round($this->variants->sum(fn (ProductVariant $variant) => (float) $variant->stock_quantity), 3);
    }

    /**
     * What the stock across all variants cost to buy.
     */
    public function stockCostValue(): float
    {
        return round($this->variants->sum(fn (ProductVariant $variant) => $variant->costValue()), 2);
    }

    /**
     * What the stock across all variants would sell for.
     */
    public function stockSellingValue(): float
    {
        return round($this->variants->sum(fn (ProductVariant $variant) => $variant->sellingValue()), 2);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'gst_rate' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }
}
