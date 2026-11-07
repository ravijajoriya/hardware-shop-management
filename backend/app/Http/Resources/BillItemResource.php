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
            // The unit it was sold in ("coil" or "m"), how many loose units one of it is, and the
            // loose unit stock is counted in.
            'unit' => $this->unit,
            'unit_size' => (float) $this->unit_size,
            'base_unit' => $this->looseUnit(),
            'sold_as' => $this->isInPacks() ? 'pack' : 'loose',
            'quantity' => (float) $this->quantity,
            'returned_quantity' => (float) $this->returned_quantity,
            'price' => (float) $this->price,
            'list_price' => (float) $this->list_price,
            'line_total' => (float) $this->line_total,
            // The size as it is now, for editing the bill: its stock (in loose units) and whether
            // the line's unit takes decimals. Packs are always whole.
            'current' => $this->when(
                $this->relationLoaded('variant') && $this->variant?->relationLoaded('product'),
                fn () => $this->variant ? [
                    'stock_quantity' => (float) $this->variant->stock_quantity,
                    'allow_decimal' => ! $this->isInPacks() && (bool) $this->variant->product?->unit?->allow_decimal,
                    'unit_name' => $this->isInPacks()
                        ? ($this->variant->product?->packUnit?->name ?? $this->unit)
                        : $this->variant->product?->unit?->name,
                    // The pack it is bought in, to show stock as "2 coils + 30 m".
                    'pack' => $this->variant->product?->packSize() ? [
                        'unit' => [
                            'name' => $this->variant->product->packUnit->name,
                            'short_name' => $this->variant->product->packUnit->short_name,
                        ],
                        'size' => $this->variant->product->packSize(),
                    ] : null,
                ] : null,
            ),
        ];
    }
}
