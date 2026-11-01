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
            'purchase_price' => (float) $this->purchase_price,
            'selling_price' => (float) $this->selling_price,
            'mrp' => $this->mrp === null ? null : (float) $this->mrp,
            'stock_quantity' => (float) $this->stock_quantity,
            'stock_value' => [
                'cost' => $this->costValue(),
                'selling' => $this->sellingValue(),
            ],
        ];
    }
}
