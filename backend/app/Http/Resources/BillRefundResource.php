<?php

namespace App\Http\Resources;

use App\Models\BillRefund;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin BillRefund
 */
class BillRefundResource extends JsonResource
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
            'bill_return_id' => $this->bill_return_id,
            'reason' => [
                'value' => $this->reason->value,
                'label' => $this->reason->label(),
            ],
            'method' => [
                'value' => $this->method->value,
                'label' => $this->method->label(),
            ],
            'amount' => (float) $this->amount,
            'reference' => $this->reference,
            'refunded_at' => $this->refunded_at,
        ];
    }
}
