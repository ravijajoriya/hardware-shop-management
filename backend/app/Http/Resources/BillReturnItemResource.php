<?php

namespace App\Http\Resources;

use App\Models\BillReturnItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin BillReturnItem
 */
class BillReturnItemResource extends JsonResource
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
            'bill_item_id' => $this->bill_item_id,
            'product_name' => $this->product_name,
            'variant_name' => $this->variant_name,
            'unit' => $this->unit,
            'quantity' => (float) $this->quantity,
            'price' => (float) $this->price,
            'line_total' => (float) $this->line_total,
        ];
    }
}
