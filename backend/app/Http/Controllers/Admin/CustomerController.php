<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CustomerIndexRequest;
use App\Http\Requests\Admin\LookupCustomerRequest;
use App\Http\Requests\Admin\PayDuesRequest;
use App\Http\Resources\CustomerResource;
use App\Models\User;
use App\Services\BillService;
use App\Services\CustomerService;
use App\Services\ShopService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CustomerController extends Controller
{
    public function __construct(
        private readonly CustomerService $customers,
        private readonly BillService $bills,
        private readonly ShopService $shop,
    ) {}

    /**
     * List customers with what they were billed and what they owe.
     */
    public function index(CustomerIndexRequest $request): AnonymousResourceCollection
    {
        return CustomerResource::collection($this->customers->paginate($request->validated()));
    }

    /**
     * Find a customer by mobile number while a bill is being made; "data" is null for a new number.
     */
    public function lookup(LookupCustomerRequest $request): JsonResponse
    {
        $customer = $this->customers->lookup($request->validated('mobile'));

        return response()->json(['data' => $customer ? new CustomerResource($customer) : null]);
    }

    /**
     * Show a customer with their bills and payments, and the shop details for reminders.
     */
    public function show(User $customer): CustomerResource
    {
        return (new CustomerResource($this->customers->details($customer)))
            ->additional(['shop' => $this->shop->details()]);
    }

    /**
     * Take a payment towards what the customer owes; it clears their oldest bills first.
     */
    public function payDues(PayDuesRequest $request, User $customer): CustomerResource
    {
        $appliedTo = $this->bills->payDues($customer, $request->validated(), $request->user());

        return (new CustomerResource($this->customers->details($customer->refresh())))
            ->additional(['applied_to' => $appliedTo, 'shop' => $this->shop->details()]);
    }
}
