<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A user seen as a shop customer.
 *
 * @mixin User
 */
class CustomerResource extends JsonResource
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
            'mobile' => $this->mobile,
            'bills_count' => $this->whenCounted('bills'),
            'total_billed' => $this->whenHas('total_billed', fn ($total) => (float) $total),
            // What the customer still owes across all their bills (udhaar).
            'total_due' => $this->whenHas('total_due', fn ($due) => (float) $due),
            'bills' => BillResource::collection($this->whenLoaded('bills')),
            'payments' => BillPaymentResource::collection($this->whenLoaded('payments')),
            'created_at' => $this->created_at,
        ];
    }
}
