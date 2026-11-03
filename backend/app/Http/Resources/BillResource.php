<?php

namespace App\Http\Resources;

use App\Enums\BillStatus;
use App\Models\Bill;
use App\Models\BillItem;
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
                'address' => $this->customer_address,
            ],
            'total' => (float) $this->total,
            'returned_amount' => (float) $this->returned_amount,
            // What the customer has to pay once returns are taken off.
            'net_total' => round((float) $this->total - (float) $this->returned_amount, 2),
            'paid_amount' => (float) $this->paid_amount,
            'refunded_amount' => (float) $this->refunded_amount,
            'due_amount' => (float) $this->due_amount,
            'status' => [
                'value' => $this->status->value,
                'label' => $this->status->label(),
            ],
            'edited' => $this->edited_at ? [
                'at' => $this->edited_at,
                'by' => $this->whenLoaded('editor', fn () => $this->editor?->name),
            ] : null,
            'cancelled' => $this->cancelled_at ? [
                'at' => $this->cancelled_at,
                'by' => $this->whenLoaded('canceller', fn () => $this->canceller?->name),
                'reason' => $this->cancel_reason,
            ] : null,
            // What can still be done to the bill, so the page offers only that.
            'can' => $this->when($this->relationLoaded('items'), fn () => [
                'edit_items' => ! $this->isCancelled() && (float) $this->returned_amount == 0.0,
                'edit' => ! $this->isCancelled(),
                'return' => ! $this->isCancelled() && $this->items->contains(
                    fn (BillItem $item) => (float) $item->quantity > (float) $item->returned_quantity,
                ),
                'cancel' => ! $this->isCancelled() && $this->status !== BillStatus::Returned,
            ]),
            'items_count' => $this->whenCounted('items'),
            'items' => BillItemResource::collection($this->whenLoaded('items')),
            'payments' => BillPaymentResource::collection($this->whenLoaded('payments')),
            'returns' => BillReturnResource::collection($this->whenLoaded('returns')),
            'refunds' => BillRefundResource::collection($this->whenLoaded('refunds')),
            'created_at' => $this->created_at,
        ];
    }
}
