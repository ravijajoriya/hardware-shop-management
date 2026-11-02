<?php

namespace App\Http\Resources;

use App\Models\BillItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin BillItem
 */
class BillItemResource extends JsonResource
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
            'product_id' => $this->product_id,
            'product_variant_id' => $this->product_variant_id,
            'product_name' => $this->product_name,
            'variant_name' => $this->variant_name,
            'unit' => $this->unit,
            'quantity' => (float) $this->quantity,
            'price' => (float) $this->price,
            'list_price' => (float) $this->list_price,
            'line_total' => (float) $this->line_total,
        ];
    }
}
