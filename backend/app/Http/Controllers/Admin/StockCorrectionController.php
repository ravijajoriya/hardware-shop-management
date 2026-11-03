<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreStockCorrectionRequest;
use App\Http\Resources\StockCorrectionResource;
use App\Models\ProductVariant;
use App\Services\StockCorrectionService;

class StockCorrectionController extends Controller
{
    public function __construct(
        private readonly StockCorrectionService $corrections,
    ) {}

    /**
     * Set a size's stock to the count it really is, with the reason.
     */
    public function store(StoreStockCorrectionRequest $request, ProductVariant $variant): StockCorrectionResource
    {
        return new StockCorrectionResource($this->corrections->correct($variant, $request->validated(), $request->user()));
    }
}
