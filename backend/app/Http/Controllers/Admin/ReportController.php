<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SalesReportRequest;
use App\Services\ReportService;
use Illuminate\Http\JsonResponse;

class ReportController extends Controller
{
    public function __construct(
        private readonly ReportService $reports,
    ) {}

    /**
     * Sales, returns, cost, profit and money in and out for a period, by day, product and customer.
     */
    public function sales(SalesReportRequest $request): JsonResponse
    {
        return response()->json([
            'data' => $this->reports->sales($request->validated('from'), $request->validated('to')),
        ]);
    }
}
