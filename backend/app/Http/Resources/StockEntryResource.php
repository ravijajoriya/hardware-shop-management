<?php

namespace App\Http\Resources;

use App\Models\StockEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StockEntry
 */
class StockEntryResource extends JsonResource
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
            'supplier_name' => $this->supplier_name,
            'supplier_bill_number' => $this->supplier_bill_number,
            'received_on' => $this->received_on->toDateString(),
            'note' => $this->note,
            'total' => (float) $this->total,
            'items_count' => $this->whenCounted('items'),
            'items' => StockEntryItemResource::collection($this->whenLoaded('items')),
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            'created_at' => $this->created_at,
        ];
    }
}
