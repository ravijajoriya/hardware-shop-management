<?php

namespace App\Models;

use App\Enums\BillStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property BillStatus $status
 */
#[Fillable([
    'bill_number', 'share_token', 'customer_id', 'customer_name', 'customer_mobile',
    'customer_address', 'total', 'paid_amount', 'returned_amount', 'refunded_amount', 'due_amount',
    'status', 'created_by', 'edited_at', 'edited_by', 'cancelled_at', 'cancelled_by', 'cancel_reason',
])]
#[Hidden(['share_token'])]
class Bill extends Model
{
    /**
     * The customer the bill is for.
     *
     * @return BelongsTo<User, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    /**
     * The admin who made the bill.
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The admin who last edited the bill.
     *
     * @return BelongsTo<User, $this>
     */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'edited_by');
    }

    /**
     * The admin who cancelled the bill.
     *
     * @return BelongsTo<User, $this>
     */
    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /**
     * @return HasMany<BillItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(BillItem::class)->orderBy('id');
    }

    /**
     * The payments made against the bill, oldest first.
     *
     * @return HasMany<BillPayment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(BillPayment::class)->orderBy('received_at')->orderBy('id');
    }

    /**
     * Items the customer brought back, oldest first.
     *
     * @return HasMany<BillReturn, $this>
     */
    public function returns(): HasMany
    {
        return $this->hasMany(BillReturn::class)->orderBy('returned_at')->orderBy('id');
    }

    /**
     * Money given back to the customer, oldest first.
     *
     * @return HasMany<BillRefund, $this>
     */
    public function refunds(): HasMany
    {
        return $this->hasMany(BillRefund::class)->orderBy('refunded_at')->orderBy('id');
    }

    public function isCancelled(): bool
    {
        return $this->status === BillStatus::Cancelled;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'total' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'returned_amount' => 'decimal:2',
            'refunded_amount' => 'decimal:2',
            'due_amount' => 'decimal:2',
            'status' => BillStatus::class,
            'edited_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }
}
