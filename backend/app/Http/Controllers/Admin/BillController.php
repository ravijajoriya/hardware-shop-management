<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BillIndexRequest;
use App\Http\Requests\Admin\PayDuesRequest;
use App\Http\Requests\Admin\StoreBillRequest;
use App\Http\Resources\BillResource;
use App\Models\Bill;
use App\Services\BillPdfService;
use App\Services\BillService;
use App\Services\ShopService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class BillController extends Controller
{
    public function __construct(
        private readonly BillService $bills,
        private readonly BillPdfService $pdfs,
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

    /**
     * Take a payment towards this bill only.
     */
    public function receivePayment(PayDuesRequest $request, Bill $bill): BillResource
    {
        return (new BillResource($this->bills->payBill($bill, $request->validated(), $request->user())))
            ->additional(['shop' => $this->shop->details()]);
    }

    /**
     * The bill as a PDF, to share on WhatsApp or download.
     */
    public function pdf(Bill $bill): Response
    {
        return response($this->pdfs->render($bill), 200, $this->pdfs->headers($bill));
    }
}
