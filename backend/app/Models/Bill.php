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
    'total', 'paid_amount', 'due_amount', 'status', 'created_by',
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
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'total' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'due_amount' => 'decimal:2',
            'status' => BillStatus::class,
        ];
    }
}
