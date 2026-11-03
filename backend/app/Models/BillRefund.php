<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\RefundReason;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money given back to a customer.
 */
#[Fillable(['bill_return_id', 'reason', 'method', 'amount', 'reference', 'refunded_at', 'refunded_by'])]
class BillRefund extends Model
{
    /**
     * @return BelongsTo<Bill, $this>
     */
    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class);
    }

    /**
     * The return it was given back for, if it was.
     *
     * @return BelongsTo<BillReturn, $this>
     */
    public function billReturn(): BelongsTo
    {
        return $this->belongsTo(BillReturn::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reason' => RefundReason::class,
            'method' => PaymentMethod::class,
            'amount' => 'decimal:2',
            'refunded_at' => 'datetime',
        ];
    }
}
