<?php

namespace App\Services;

use App\Enums\PaymentMethod;
use App\Repositories\ReportRepository;
use App\Support\Money;
use App\Support\ShopCalendar;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Sales, returns, cost and profit for a period, by the shop's calendar.
 *
 * Sales count on the day a bill was made and returns on the day the items came back, so a
 * return can fall in a later period than its sale. Cancelled bills are not sales. Cost is
 * what each item cost the shop when it was sold (its average purchase price then); for bills
 * made before costs were kept it is an estimate, and the report says so.
 */
class ReportService
{
    private const TOP = 10;

    public function __construct(private readonly ReportRepository $reports) {}

    /**
     * @param  string  $from  First day, Y-m-d.
     * @param  string  $to  Last day, Y-m-d, included.
     * @return array<string, mixed>
     */
    public function sales(string $from, string $to): array
    {
        [$start, $until] = $this->period($from, $to);

        $sold = $this->reports->soldLines($start, $until);
        $returned = $this->reports->returnedLines($start, $until);
        $payments = $this->reports->payments($start, $until);
        $refunds = $this->reports->refunds($start, $until);

        return [
            'from' => $from,
            'to' => $to,
            'summary' => $this->summary($sold, $returned, $payments, $refunds),
            'days' => $this->days($sold, $returned, $payments, $refunds),
            'products' => $this->products($sold, $returned),
            'customers' => $this->customers($sold, $returned),
        ];
    }

    /**
     * Just the totals, such as for today on the dashboard.
     *
     * @return array<string, mixed>
     */
    public function totals(string $from, string $to): array
    {
        [$start, $until] = $this->period($from, $to);

        return $this->summary(
            $this->reports->soldLines($start, $until),
            $this->reports->returnedLines($start, $until),
            $this->reports->payments($start, $until),
            $this->reports->refunds($start, $until),
        );
    }

    /**
     * @return array{0: Carbon, 1: Carbon} The first moment of $from and the first moment after $to, in UTC.
     */
    private function period(string $from, string $to): array
    {
        return [ShopCalendar::start($from), ShopCalendar::after($to)];
    }

    /**
     * @param  Collection<int, object>  $sold
     * @param  Collection<int, object>  $returned
     * @param  Collection<int, object>  $payments
     * @param  Collection<int, object>  $refunds
     * @return array<string, mixed>
     */
    private function summary(Collection $sold, Collection $returned, Collection $payments, Collection $refunds): array
    {
        $sales = $this->sum($sold, 'line_total');
        $returns = $this->sum($returned, 'line_total');
        $cost = $this->cost($sold) - $this->cost($returned);
        $received = $this->byMethod($payments);
        $refunded = $this->byMethod($refunds);

        return [
            'bills' => $sold->pluck('bill_id')->unique()->count(),
            ...$this->figures($sales, $returns, $cost),
            'cost_estimated' => $sold->contains(fn (object $line) => $line->cost_estimated || $line->cost_price === null)
                || $returned->contains(fn (object $line) => $line->cost_price === null),
            'received' => $received,
            'refunded' => $refunded,
            'net_received' => Money::toRupees(Money::toPaise($received['total']) - Money::toPaise($refunded['total'])),
        ];
    }

    /**
     * Each day with any sales, returns or money in or out, oldest first.
     *
     * @return list<array<string, mixed>>
     */
    private function days(Collection $sold, Collection $returned, Collection $payments, Collection $refunds): array
    {
        $sold = $sold->groupBy(fn (object $row) => $this->day($row->at));
        $returned = $returned->groupBy(fn (object $row) => $this->day($row->at));
        $payments = $payments->groupBy(fn (object $row) => $this->day($row->at));
        $refunds = $refunds->groupBy(fn (object $row) => $this->day($row->at));

        $dates = collect([...$sold->keys(), ...$returned->keys(), ...$payments->keys(), ...$refunds->keys()])->unique()->sort()->values();

        return $dates->map(function (string $date) use ($sold, $returned, $payments, $refunds) {
            $daySold = $sold->get($date, collect());
            $dayReturned = $returned->get($date, collect());

            return [
                'date' => $date,
                'bills' => $daySold->pluck('bill_id')->unique()->count(),
                ...$this->figures(
                    $this->sum($daySold, 'line_total'),
                    $this->sum($dayReturned, 'line_total'),
                    $this->cost($daySold) - $this->cost($dayReturned),
                ),
                'received' => Money::toRupees($this->sum($payments->get($date, collect()), 'amount')),
                'refunded' => Money::toRupees($this->sum($refunds->get($date, collect()), 'amount')),
            ];
        })->all();
    }

    /**
     * The products that brought in the most, after returns.
     *
     * @return list<array<string, mixed>>
     */
    private function products(Collection $sold, Collection $returned): array
    {
        // Lines are matched by product, or by name for products deleted since.
        $key = fn (object $line) => ($line->product_id ?? 'deleted:'.$line->product_name).'|'.$line->variant_name;
        $sold = $sold->groupBy($key);
        $returned = $returned->groupBy($key);

        return $sold->keys()->merge($returned->keys())->unique()
            ->map(function (string $product) use ($sold, $returned) {
                $out = $sold->get($product, collect());
                $back = $returned->get($product, collect());
                $line = $out->first() ?? $back->first();
                $amount = $this->sum($out, 'line_total') - $this->sum($back, 'line_total');

                // Counted in the loose unit, so 2 coils and 15 m of wire add up to 195 m.
                return [
                    'product_id' => $line->product_id,
                    'name' => $line->product_name,
                    'variant_name' => $line->variant_name,
                    'unit' => $line->base_unit ?? $line->unit,
                    'quantity' => round($this->looseQuantity($out) - $this->looseQuantity($back), 3),
                    'amount' => Money::toRupees($amount),
                    'profit' => Money::toRupees($amount - $this->cost($out) + $this->cost($back)),
                ];
            })
            // Only what actually sold: one sold and brought back nets to nothing.
            ->filter(fn (array $row) => $row['amount'] > 0)
            ->sortByDesc('amount')
            ->take(self::TOP)
            ->values()
            ->all();
    }

    /**
     * The customers who bought the most, after returns, with what they owe now.
     *
     * @return list<array<string, mixed>>
     */
    private function customers(Collection $sold, Collection $returned): array
    {
        $sold = $sold->groupBy('customer_id');
        $returned = $returned->groupBy('customer_id');

        $top = $sold->keys()->merge($returned->keys())->unique()
            ->map(function ($customer) use ($sold, $returned) {
                $out = $sold->get($customer, collect());
                $back = $returned->get($customer, collect());

                return [
                    'id' => (int) $customer,
                    // Their name on their latest bill in the period.
                    'name' => ($out->last() ?? $back->last())->customer_name,
                    'bills' => $out->pluck('bill_id')->unique()->count(),
                    'amount' => Money::toRupees($this->sum($out, 'line_total') - $this->sum($back, 'line_total')),
                ];
            })
            // Only what actually sold: one sold and brought back nets to nothing.
            ->filter(fn (array $row) => $row['amount'] > 0)
            ->sortByDesc('amount')
            ->take(self::TOP)
            ->values();

        $dues = $this->reports->duesOf($top->pluck('id')->all());

        return $top->map(fn (array $customer) => [...$customer, 'due' => $dues[$customer['id']] ?? 0.0])->all();
    }

    /**
     * Net sales, cost, profit and margin from amounts in paise.
     *
     * @return array{sales: float, returns: float, net_sales: float, cost: float, profit: float, margin: float|null}
     */
    private function figures(int $sales, int $returns, int $cost): array
    {
        $net = $sales - $returns;
        $profit = $net - $cost;

        return [
            'sales' => Money::toRupees($sales),
            'returns' => Money::toRupees($returns),
            'net_sales' => Money::toRupees($net),
            'cost' => Money::toRupees($cost),
            'profit' => Money::toRupees($profit),
            // Profit as a share of net sales: 25.0 means a quarter of every rupee taken was profit.
            'margin' => $net > 0 ? round($profit / $net * 100, 1) : null,
        ];
    }

    /**
     * Rupees by payment method, and their total: ['cash' => 1200.0, 'upi' => 800.0, …, 'total' => 2000.0].
     *
     * @return array<string, float>
     */
    private function byMethod(Collection $rows): array
    {
        $totals = [];

        foreach (PaymentMethod::cases() as $method) {
            $totals[$method->value] = Money::toRupees($this->sum($rows->where('method', $method->value), 'amount'));
        }

        $totals['total'] = Money::toRupees($this->sum($rows, 'amount'));

        return $totals;
    }

    /**
     * What the lines cost the shop, in paise. A line without a cost counts as costing nothing.
     */
    private function cost(Collection $lines): int
    {
        return (int) $lines->sum(fn (object $line) => round((float) $line->quantity * Money::toPaise($line->cost_price)));
    }

    /**
     * How much the lines come to in loose units: a line of 2 coils of 90 m is 180 m.
     */
    private function looseQuantity(Collection $lines): float
    {
        return (float) $lines->sum(fn (object $line) => (float) $line->quantity * (float) ($line->unit_size ?? 1));
    }

    private function sum(Collection $rows, string $column): int
    {
        return (int) $rows->sum(fn (object $row) => Money::toPaise($row->{$column}));
    }

    /**
     * The shop's calendar day of a stored (UTC) moment.
     */
    private function day(string $moment): string
    {
        return Carbon::parse($moment, 'UTC')->setTimezone(config('shop.timezone'))->toDateString();
    }
}
