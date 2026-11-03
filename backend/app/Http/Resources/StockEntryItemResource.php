<?php

namespace App\Http\Resources;

use App\Models\StockEntryItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StockEntryItem
 */
class StockEntryItemResource extends JsonResource
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
            'purchase_price' => (float) $this->purchase_price,
            'line_total' => (float) $this->line_total,
            // The size's average cost before and after this delivery.
            'cost_before' => $this->cost_before === null ? null : (float) $this->cost_before,
            'cost_after' => (float) $this->cost_after,
        ];
    }
}
