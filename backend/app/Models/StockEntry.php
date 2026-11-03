<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One delivery of stock coming into the shop, with what came in and what it cost.
 */
#[Fillable(['supplier_name', 'supplier_bill_number', 'received_on', 'note', 'total', 'created_by'])]
class StockEntry extends Model
{
    /**
     * @return HasMany<StockEntryItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(StockEntryItem::class)->orderBy('id');
    }

    /**
     * The admin who entered it.
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
            'received_on' => 'date',
            'total' => 'decimal:2',
        ];
    }
}
