<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\DashboardService;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function __construct(private readonly DashboardService $dashboard) {}

    /**
     * Today's sales and collection, and what customers owe overall.
     */
    public function summary(): JsonResponse
    {
        return response()->json(['data' => $this->dashboard->today()]);
    }
}
