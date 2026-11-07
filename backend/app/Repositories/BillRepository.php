<?php

namespace App\Repositories;

use App\Enums\BillStatus;
use App\Models\Bill;
use App\Models\BillItem;
use App\Models\BillPayment;
use App\Models\BillRefund;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonInterface;
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
     * Matching bills, newest first unless sorted otherwise.
     *
     * @param  array<string, mixed>  $filters  As for filtered(), plus sort: newest, oldest, total or due.
     * @return LengthAwarePaginator<int, Bill>
     */
    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        $query = $this->filtered($filters)->withCount('items');

        match ($filters['sort'] ?? null) {
            'oldest' => $query->oldest('created_at')->oldest('id'),
            'total' => $query->orderByDesc('total')->latest('created_at')->latest('id'),
            // The biggest due first and, between equal dues, the one owed longest.
            'due' => $query->orderByDesc('due_amount')->oldest('created_at')->oldest('id'),
            default => $query->latest('created_at')->latest('id'),
        };

        return $query->paginate($perPage)->withQueryString();
    }

    /**
     * What all the matching bills add up to. Cancelled bills are counted but their money is
     * not added in, and what was billed is after returns.
     *
     * @param  array<string, mixed>  $filters  As for filtered().
     * @return array{bills: int, cancelled: int, billed: float, returned: float, due: float}
     */
    public function totals(array $filters): array
    {
        $cancelled = BillStatus::Cancelled->value;

        $row = $this->filtered($filters)
            ->toBase()
            ->selectRaw('count(*) as bills')
            ->selectRaw('sum(case when status = ? then 1 else 0 end) as cancelled', [$cancelled])
            ->selectRaw('sum(case when status = ? then 0 else total - returned_amount end) as billed', [$cancelled])
            ->selectRaw('sum(case when status = ? then 0 else returned_amount end) as returned', [$cancelled])
            ->selectRaw('sum(due_amount) as due')
            ->first();

        return [
            'bills' => (int) $row->bills,
            'cancelled' => (int) $row->cancelled,
            'billed' => Money::toRupees(Money::toPaise($row->billed ?? 0)),
            'returned' => Money::toRupees(Money::toPaise($row->returned ?? 0)),
            'due' => Money::toRupees(Money::toPaise($row->due ?? 0)),
        ];
    }

    /**
     * Bills matching the list filters. "from" and "until" are moments (until itself not
     * included); "due_before" keeps bills made before it that still have money due;
     * "changes" is edited or returned.
     *
     * @param  array{search?: string|null, status?: string|null, from?: CarbonInterface|null, until?: CarbonInterface|null, method?: string|null, product?: string|null, due_before?: CarbonInterface|null, changes?: string|null}  $filters
     * @return Builder<Bill>
     */
    private function filtered(array $filters): Builder
    {
        return Bill::query()
            ->when($filters['search'] ?? null, function (Builder $query, string $search) {
                $query->where(function (Builder $query) use ($search) {
                    $query->where('bill_number', 'like', "%{$search}%")
                        ->orWhere('customer_name', 'like', "%{$search}%")
                        ->orWhere('customer_mobile', 'like', "%{$search}%");
                });
            })
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['from'] ?? null, fn (Builder $query, CarbonInterface $from) => $query->where('created_at', '>=', $from))
            ->when($filters['until'] ?? null, fn (Builder $query, CarbonInterface $until) => $query->where('created_at', '<', $until))
            ->when($filters['method'] ?? null, fn (Builder $query, string $method) => $query->whereHas(
                'payments',
                fn (Builder $query) => $query->where('method', $method),
            ))
            // The name and size are as printed on the bill, so lines of deleted products still match.
            ->when($filters['product'] ?? null, fn (Builder $query, string $product) => $query->whereHas(
                'items',
                fn (Builder $query) => $query->where(function (Builder $query) use ($product) {
                    $query->where('product_name', 'like', "%{$product}%")
                        ->orWhere('variant_name', 'like', "%{$product}%")
                        ->orWhereHas('variant', fn (Builder $query) => $query->where('sku', 'like', "%{$product}%"));
                }),
            ))
            ->when($filters['due_before'] ?? null, fn (Builder $query, CarbonInterface $before) => $query
                ->where('due_amount', '>', 0)
                ->where('created_at', '<', $before))
            ->when(($filters['changes'] ?? null) === 'edited', fn (Builder $query) => $query->whereNotNull('edited_at'))
            ->when(($filters['changes'] ?? null) === 'returned', fn (Builder $query) => $query->where('returned_amount', '>', 0));
    }

    /**
     * Everything the bill page shows. Each line's size and unit come too, for editing.
     */
    public function loadDetails(Bill $bill): Bill
    {
        return $bill->load([
            'items.variant.product.unit',
            'items.variant.product.packUnit',
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
        return Bill::whereKey($bill->getKey())->lockForUpdate()->firstOrFail()->load(['items.variant.product.unit', 'items.variant.product.packUnit']);
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
