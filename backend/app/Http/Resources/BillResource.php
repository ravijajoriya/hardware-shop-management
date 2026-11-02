<?php

namespace App\Http\Resources;

use App\Models\Bill;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Bill
 */
class BillResource extends JsonResource
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
            'bill_number' => $this->bill_number,
            // The private link the customer opens the bill's PDF from.
            'share_url' => $this->share_token ? route('bills.shared', $this->share_token) : null,
            // As printed on the bill, even if the customer's details change later.
            'customer' => [
                'id' => $this->customer_id,
                'name' => $this->customer_name,
                'mobile' => $this->customer_mobile,
            ],
            'total' => (float) $this->total,
            'paid_amount' => (float) $this->paid_amount,
            'due_amount' => (float) $this->due_amount,
            'status' => [
                'value' => $this->status->value,
                'label' => $this->status->label(),
            ],
            'items_count' => $this->whenCounted('items'),
            'items' => BillItemResource::collection($this->whenLoaded('items')),
            'payments' => BillPaymentResource::collection($this->whenLoaded('payments')),
            'created_at' => $this->created_at,
        ];
    }
}
