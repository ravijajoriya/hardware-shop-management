<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreBillReturnRequest;
use App\Http\Resources\BillResource;
use App\Models\Bill;
use App\Services\BillReturnService;
use App\Services\BillService;
use App\Services\ShopService;

class BillReturnController extends Controller
{
    public function __construct(
        private readonly BillReturnService $returns,
        private readonly BillService $bills,
        private readonly ShopService $shop,
    ) {}

    /**
     * Take items back from a bill, and give the bill back with the return on it.
     */
    public function store(StoreBillReturnRequest $request, Bill $bill): BillResource
    {
        $return = $this->returns->create($bill, $request->validated(), $request->user());

        return (new BillResource($this->bills->details($bill->refresh())))
            ->additional(['shop' => $this->shop->details(), 'return_number' => $return->return_number]);
    }
}
