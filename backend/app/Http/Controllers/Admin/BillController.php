<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BillIndexRequest;
use App\Http\Requests\Admin\StoreBillRequest;
use App\Http\Resources\BillResource;
use App\Models\Bill;
use App\Services\BillService;
use App\Services\ShopService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BillController extends Controller
{
    public function __construct(
        private readonly BillService $bills,
        private readonly ShopService $shop,
    ) {}

    /**
     * List bills, newest first.
     */
    public function index(BillIndexRequest $request): AnonymousResourceCollection
    {
        return BillResource::collection($this->bills->paginate($request->validated()));
    }

    /**
     * Make a bill for a customer.
     */
    public function store(StoreBillRequest $request): BillResource
    {
        return new BillResource($this->bills->create($request->validated(), $request->user()));
    }

    /**
     * Show a bill with its items and payments, and the shop details to print above it.
     */
    public function show(Bill $bill): BillResource
    {
        return (new BillResource($this->bills->details($bill)))
            ->additional(['shop' => $this->shop->details()]);
    }
}
