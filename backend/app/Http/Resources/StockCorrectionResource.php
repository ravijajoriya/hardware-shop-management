<?php

namespace App\Http\Resources;

use App\Models\StockCorrection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StockCorrection
 */
class StockCorrectionResource extends JsonResource
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
            'stock_before' => (float) $this->stock_before,
            'stock_after' => (float) $this->stock_after,
            'reason' => [
                'value' => $this->reason->value,
                'label' => $this->reason->label(),
            ],
            'note' => $this->note,
            'created_at' => $this->created_at,
        ];
    }
}
