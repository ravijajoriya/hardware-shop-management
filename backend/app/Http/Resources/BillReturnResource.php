<?php

namespace App\Http\Resources;

use App\Models\BillReturn;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin BillReturn
 */
class BillReturnResource extends JsonResource
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
            'return_number' => $this->return_number,
            'amount' => (float) $this->amount,
            'due_reduced' => (float) $this->due_reduced,
            'refund_amount' => (float) $this->refund_amount,
            'note' => $this->note,
            'returned_at' => $this->returned_at,
            'items' => BillReturnItemResource::collection($this->whenLoaded('items')),
            'refund' => $this->whenLoaded('refund', fn () => $this->refund ? new BillRefundResource($this->refund) : null),
        ];
    }
}
