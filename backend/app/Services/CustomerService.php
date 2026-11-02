<?php

namespace App\Services;

use App\Models\User;
use App\Repositories\CustomerRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class CustomerService
{
    public function __construct(private readonly CustomerRepository $customers) {}

    /**
     * @param  array<string, mixed>  $filters  Validated list filters: search, with_due, per_page.
     * @return LengthAwarePaginator<int, User>
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        return $this->customers->paginate($filters, (int) ($filters['per_page'] ?? 20));
    }

    /**
     * The customer with this mobile number, with their totals, for filling in a new bill.
     */
    public function lookup(string $mobile): ?User
    {
        $customer = $this->customers->findByMobile($mobile);

        return $customer && ! $customer->is_admin ? $this->customers->loadTotals($customer) : null;
    }

    /**
     * The customer with their totals, every bill and every payment.
     */
    public function details(User $customer): User
    {
        return $this->customers->loadHistory($this->customers->loadTotals($customer));
    }

    /**
     * The customer with this mobile number, added if new. A different name typed for
     * a known number replaces the saved one, since the shopkeeper has just checked it.
     */
    public function resolve(string $name, string $mobile): User
    {
        $customer = $this->customers->findByMobile($mobile);

        if (! $customer) {
            return $this->customers->create(['name' => $name, 'mobile' => $mobile]);
        }

        if (! $customer->is_admin && $customer->name !== $name) {
            $this->customers->rename($customer, $name);
        }

        return $customer;
    }
}
