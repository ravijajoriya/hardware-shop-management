<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\StockHistoryService;
use Illuminate\Http\JsonResponse;

class StockHistoryController extends Controller
{
    public function __construct(
        private readonly StockHistoryService $history,
    ) {}

    /**
     * Every delivery and stock correction for the product, newest first.
     */
    public function show(Product $product): JsonResponse
    {
        return response()->json(['data' => $this->history->forProduct($product)]);
    }
}
