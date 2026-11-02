<?php

namespace App\Services;

use App\Enums\PaymentMethod;
use App\Repositories\BillRepository;
use App\Support\Money;

class DashboardService
{
    public function __construct(private readonly BillRepository $bills) {}

    /**
     * Today's bills and the money taken today by method, with what customers owe overall.
     * "Today" follows the shop's clock, not the server's.
     *
     * @return array{
     *     date: string,
     *     bills: int,
     *     sales: float,
     *     collected: array<string, float>,
     *     due: array{total: float, customers: int},
     * }
     */
    public function today(): array
    {
        $now = now(config('shop.timezone'));
        $from = $now->copy()->startOfDay()->utc();
        $to = $now->copy()->endOfDay()->utc();

        $sales = $this->bills->salesBetween($from, $to);
        $byMethod = $this->bills->collectedBetween($from, $to);

        $collected = [];

        foreach (PaymentMethod::cases() as $method) {
            $collected[$method->value] = $byMethod[$method->value] ?? 0.0;
        }

        $collected['total'] = Money::toRupees(array_sum(array_map(Money::toPaise(...), $collected)));

        return [
            'date' => $now->toDateString(),
            'bills' => $sales['count'],
            'sales' => $sales['total'],
            'collected' => $collected,
            'due' => $this->bills->dues(),
        ];
    }
}
