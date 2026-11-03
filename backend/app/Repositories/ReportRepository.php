<?php

namespace App\Repositories;

use App\Enums\BillStatus;
use App\Models\Bill;
use App\Models\BillItem;
use App\Models\BillPayment;
use App\Models\BillRefund;
use App\Models\BillReturnItem;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;

/**
 * The raw rows behind the sales reports. Cancelled bills are left out: they are not sales, and
 * nor are any returns taken from them.
 */
class ReportRepository
{
    /**
     * Every line on the bills made in the period.
     *
     * @return Collection<int, object>
     */
    public function soldLines(CarbonInterface $from, CarbonInterface $until): Collection
    {
        return BillItem::query()
            ->join('bills', 'bills.id', '=', 'bill_items.bill_id')
            ->where('bills.status', '!=', BillStatus::Cancelled->value)
            ->where('bills.created_at', '>=', $from)
            ->where('bills.created_at', '<', $until)
            ->toBase()
            ->get([
                'bills.id as bill_id', 'bills.created_at as at', 'bills.customer_id', 'bills.customer_name',
                'bill_items.product_id', 'bill_items.product_name', 'bill_items.variant_name', 'bill_items.unit',
                'bill_items.quantity', 'bill_items.line_total', 'bill_items.cost_price', 'bill_items.cost_estimated',
            ]);
    }

    /**
     * Every line brought back in the period, whenever its bill was made.
     *
     * @return Collection<int, object>
     */
    public function returnedLines(CarbonInterface $from, CarbonInterface $until): Collection
    {
        return BillReturnItem::query()
            ->join('bill_returns', 'bill_returns.id', '=', 'bill_return_items.bill_return_id')
            ->join('bills', 'bills.id', '=', 'bill_returns.bill_id')
            ->where('bills.status', '!=', BillStatus::Cancelled->value)
            ->where('bill_returns.returned_at', '>=', $from)
            ->where('bill_returns.returned_at', '<', $until)
            ->toBase()
            ->get([
                'bill_returns.returned_at as at', 'bills.customer_id', 'bills.customer_name',
                'bill_return_items.product_id', 'bill_return_items.product_name', 'bill_return_items.variant_name',
                'bill_return_items.unit', 'bill_return_items.quantity', 'bill_return_items.line_total',
                'bill_return_items.cost_price',
            ]);
    }

    /**
     * Money taken in the period, on any bill.
     *
     * @return Collection<int, object>
     */
    public function payments(CarbonInterface $from, CarbonInterface $until): Collection
    {
        return $this->inPeriod(BillPayment::query()->toBase(), 'received_at', $from, $until)
            ->get(['received_at as at', 'method', 'amount']);
    }

    /**
     * Money given back in the period.
     *
     * @return Collection<int, object>
     */
    public function refunds(CarbonInterface $from, CarbonInterface $until): Collection
    {
        return $this->inPeriod(BillRefund::query()->toBase(), 'refunded_at', $from, $until)
            ->get(['refunded_at as at', 'method', 'amount']);
    }

    /**
     * What each of these customers owes now, on all their bills: [customer id => rupees].
     *
     * @param  list<int>  $customerIds
     * @return array<int, float>
     */
    public function duesOf(array $customerIds): array
    {
        return Bill::query()
            ->whereIn('customer_id', $customerIds)
            ->groupBy('customer_id')
            ->selectRaw('customer_id, SUM(due_amount) AS due')
            ->toBase()
            ->pluck('due', 'customer_id')
            ->map(fn ($due) => (float) $due)
            ->all();
    }

    private function inPeriod(QueryBuilder $query, string $column, CarbonInterface $from, CarbonInterface $until): QueryBuilder
    {
        return $query->where($column, '>=', $from)->where($column, '<', $until);
    }
}
