<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StockEntryIndexRequest;
use App\Http\Requests\Admin\StoreStockEntryRequest;
use App\Http\Resources\StockEntryResource;
use App\Models\StockEntry;
use App\Services\StockEntryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class StockEntryController extends Controller
{
    public function __construct(
        private readonly StockEntryService $entries,
    ) {}

    /**
     * List deliveries, newest first.
     */
    public function index(StockEntryIndexRequest $request): AnonymousResourceCollection
    {
        return StockEntryResource::collection($this->entries->paginate($request->validated()));
    }

    /**
     * Log a delivery and add it to stock.
     */
    public function store(StoreStockEntryRequest $request): StockEntryResource
    {
        return new StockEntryResource($this->entries->create($request->validated(), $request->user()));
    }

    /**
     * Show a delivery with what came in.
     */
    public function show(StockEntry $stockEntry): StockEntryResource
    {
        return new StockEntryResource($this->entries->details($stockEntry));
    }

    /**
     * Delete a delivery entered by mistake, taking its stock back out.
     */
    public function destroy(StockEntry $stockEntry): Response
    {
        $this->entries->delete($stockEntry);

        return response()->noContent();
    }

    /**
     * Supplier names used before, to suggest while typing.
     */
    public function suppliers(): JsonResponse
    {
        return response()->json(['data' => $this->entries->supplierNames()]);
    }
}
