<?php

namespace App\Services;

use App\Repositories\BillRepository;

class DashboardService
{
    public function __construct(
        private readonly BillRepository $bills,
        private readonly ReportService $reports,
    ) {}

    /**
     * Today's sales (after returns) and profit, the money taken and given back today by method,
     * and what customers owe overall. "Today" follows the shop's clock, not the server's.
     *
     * @return array<string, mixed>
     */
    public function today(): array
    {
        $today = now(config('shop.timezone'))->toDateString();
        $totals = $this->reports->totals($today, $today);

        return [
            'date' => $today,
            'bills' => $totals['bills'],
            'sales' => $totals['net_sales'],
            'returns' => $totals['returns'],
            'profit' => $totals['profit'],
            'margin' => $totals['margin'],
            'cost_estimated' => $totals['cost_estimated'],
            'collected' => $totals['received'],
            'refunded' => $totals['refunded'],
            'due' => $this->bills->dues(),
        ];
    }
}
