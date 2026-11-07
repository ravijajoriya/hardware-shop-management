<?php

namespace App\Http\Resources;

use App\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ProductVariant
 */
class ProductVariantResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'sku' => $this->sku,
            // Per loose unit; a product bought in packs shows it per pack by multiplying.
            'purchase_price' => (float) $this->purchase_price,
            'selling_price' => (float) $this->selling_price,
            // What a full pack sells for, when set; otherwise the loose price times the pack size.
            'pack_price' => $this->pack_price === null ? null : (float) $this->pack_price,
            'mrp' => $this->mrp === null ? null : (float) $this->mrp,
            'stock_quantity' => (float) $this->stock_quantity,
            'min_stock' => $this->min_stock === null ? null : (float) $this->min_stock,
            'low_stock' => $this->isLowOnStock(),
            'stock_value' => [
                'cost' => $this->costValue(),
                'selling' => $this->sellingValue(),
            ],
        ];
    }
}
