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
            // As entered: in packs ("coil") or loose ("m"), with the price of one of that unit.
            'unit' => $this->unit,
            'unit_size' => (float) $this->unit_size,
            'base_unit' => $this->looseUnit(),
            'entered_as' => $this->isInPacks() ? 'pack' : 'loose',
            'quantity' => (float) $this->quantity,
            'purchase_price' => (float) $this->purchase_price,
            'line_total' => (float) $this->line_total,
            // The size's average cost before and after this delivery, per the line's unit too.
            'cost_before' => $this->cost_before === null ? null : round((float) $this->cost_before * (float) $this->unit_size, 2),
            'cost_after' => round((float) $this->cost_after * (float) $this->unit_size, 2),
        ];
    }
}
