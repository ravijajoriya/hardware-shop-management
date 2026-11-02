<?php

namespace App\Http\Resources;

use App\Models\BillPayment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin BillPayment
 */
class BillPaymentResource extends JsonResource
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
            'method' => [
                'value' => $this->method->value,
                'label' => $this->method->label(),
            ],
            'amount' => (float) $this->amount,
            'reference' => $this->reference,
            'received_at' => $this->received_at,
            'bill' => $this->whenLoaded('bill', fn () => [
                'id' => $this->bill->id,
                'bill_number' => $this->bill->bill_number,
            ]),
        ];
    }
}
