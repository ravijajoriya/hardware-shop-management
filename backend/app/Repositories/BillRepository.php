<?php

namespace App\Repositories;

use App\Models\Bill;
use App\Models\BillItem;
use App\Models\BillPayment;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

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

    public function loadDetails(Bill $bill): Bill
    {
        return $bill->load(['items', 'payments']);
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
     * @param  array{paid_amount: float, due_amount: float, status: mixed}  $amounts
     */
    public function updateAmounts(Bill $bill, array $amounts): void
    {
        $bill->update($amounts);
    }

    /**
     * The customer's bills that still have money due, oldest first, locked while a payment is spread over them.
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
     * How many bills were made in the period and what they came to.
     *
     * @return array{count: int, total: float}
     */
    public function salesBetween(Carbon $from, Carbon $to): array
    {
        $row = Bill::whereBetween('created_at', [$from, $to])
            ->selectRaw('COUNT(*) AS bill_count, COALESCE(SUM(total), 0) AS total')
            ->toBase()
            ->first();

        return ['count' => (int) $row->bill_count, 'total' => (float) $row->total];
    }

    /**
     * Money received in the period, by payment method: ['cash' => 1200.0, 'upi' => 800.0].
     *
     * @return array<string, float>
     */
    public function collectedBetween(Carbon $from, Carbon $to): array
    {
        return BillPayment::whereBetween('received_at', [$from, $to])
            ->groupBy('method')
            ->selectRaw('method, SUM(amount) AS total')
            ->toBase()
            ->pluck('total', 'method')
            ->map(fn ($total) => (float) $total)
            ->all();
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
