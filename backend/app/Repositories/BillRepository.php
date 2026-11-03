<?php

namespace App\Repositories;

use App\Models\Bill;
use App\Models\BillItem;
use App\Models\BillPayment;
use App\Models\BillRefund;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class BillRepository
{
    /**
     * The newest bill number starting with the prefix, locked until the transaction
     * ends so two bills made at once cannot get the same number.
     */
    public function lastNumberStartingWith(string $prefix): ?string
    {
        return Bill::where('bill_number', 'like', $prefix.'%')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->value('bill_number');
    }

    /**
     * Bills newest first, searched by bill number, customer name or mobile.
     *
     * @param  array{search?: string|null, status?: string|null}  $filters
     * @return LengthAwarePaginator<int, Bill>
     */
    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        return Bill::query()
            ->withCount('items')
            ->when($filters['search'] ?? null, function (Builder $query, string $search) {
                $query->where(function (Builder $query) use ($search) {
                    $query->where('bill_number', 'like', "%{$search}%")
                        ->orWhere('customer_name', 'like', "%{$search}%")
                        ->orWhere('customer_mobile', 'like', "%{$search}%");
                });
            })
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->latest('created_at')
            ->latest('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * Everything the bill page shows. Each line's size and unit come too, for editing.
     */
    public function loadDetails(Bill $bill): Bill
    {
        return $bill->load([
            'items.variant.product.unit',
            'payments',
            'returns.items',
            'returns.refund',
            'refunds',
            'editor:id,name',
            'canceller:id,name',
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): Bill
    {
        return Bill::create($attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function addItem(Bill $bill, array $attributes): BillItem
    {
        return $bill->items()->create($attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function addPayment(Bill $bill, array $attributes): BillPayment
    {
        return $bill->payments()->create($attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function addRefund(Bill $bill, array $attributes): BillRefund
    {
        return $bill->refunds()->create($attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes  Such as the amounts from BillAmounts::columns().
     */
    public function update(Bill $bill, array $attributes): void
    {
        $bill->update($attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateItem(BillItem $item, array $attributes): void
    {
        $item->update($attributes);
    }

    public function deleteItem(BillItem $item): void
    {
        $item->delete();
    }

    /**
     * The bill read afresh with its lines, and locked until the transaction ends, so two
     * changes to it at once cannot both go by the same amounts.
     */
    public function lock(Bill $bill): Bill
    {
        return Bill::whereKey($bill->getKey())->lockForUpdate()->firstOrFail()->load('items.variant.product.unit');
    }

    /**
     * The customer's bills that still have money due, oldest first, locked while a payment
     * is spread over them.
     *
     * @return Collection<int, Bill>
     */
    public function unpaidFor(User $customer): Collection
    {
        return Bill::where('customer_id', $customer->id)
            ->where('due_amount', '>', 0)
            ->orderBy('created_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /**
     * What all customers owe together, and how many customers owe anything.
     *
     * @return array{total: float, customers: int}
     */
    public function dues(): array
    {
        $row = Bill::where('due_amount', '>', 0)
            ->selectRaw('COALESCE(SUM(due_amount), 0) AS total, COUNT(DISTINCT customer_id) AS customers')
            ->toBase()
            ->first();

        return ['total' => (float) $row->total, 'customers' => (int) $row->customers];
    }
}
