<?php

namespace App\Repositories;

use App\Models\Bill;
use App\Models\BillReturn;
use App\Models\BillReturnItem;

class BillReturnRepository
{
    /**
     * The newest return number starting with the prefix, locked until the transaction ends
     * so two returns taken at once cannot get the same number.
     */
    public function lastNumberStartingWith(string $prefix): ?string
    {
        return BillReturn::where('return_number', 'like', $prefix.'%')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->value('return_number');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(Bill $bill, array $attributes): BillReturn
    {
        return $bill->returns()->create($attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function addItem(BillReturn $return, array $attributes): BillReturnItem
    {
        return $return->items()->create($attributes);
    }
}
