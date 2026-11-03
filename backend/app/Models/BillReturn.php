<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Items a customer brought back from a bill. Their worth is first taken off what is still due
 * on the bill; the rest is refunded.
 */
#[Fillable(['return_number', 'amount', 'due_reduced', 'refund_amount', 'note', 'returned_at', 'created_by'])]
class BillReturn extends Model
{
    /**
     * @return BelongsTo<Bill, $this>
     */
    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class);
    }

    /**
     * @return HasMany<BillReturnItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(BillReturnItem::class)->orderBy('id');
    }

    /**
     * The money given back for this return, if any was.
     *
     * @return HasOne<BillRefund, $this>
     */
    public function refund(): HasOne
    {
        return $this->hasOne(BillRefund::class);
    }

    /**
     * The admin who took the items back.
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'due_reduced' => 'decimal:2',
            'refund_amount' => 'decimal:2',
            'returned_at' => 'datetime',
        ];
    }
}
