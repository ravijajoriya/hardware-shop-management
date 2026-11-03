<?php

namespace App\Services;

use App\Enums\ActivityAction;
use App\Models\User;
use App\Repositories\CustomerRepository;
use App\Support\ActivityChanges;
use App\Support\ActivitySnapshot;
use App\Support\MobileNumber;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class CustomerService
{
    public function __construct(
        private readonly CustomerRepository $customers,
        private readonly ActivityLogService $activity,
    ) {}

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
     * The customer with this mobile number, added if new. A different name or address typed
     * for a known number replaces the saved one, since the shopkeeper has just checked it; a
     * blank address leaves the saved one as it is.
     */
    public function resolve(string $name, string $mobile, ?string $address = null): User
    {
        $customer = $this->customers->findByMobile($mobile);

        if (! $customer) {
            $customer = $this->customers->create(['name' => $name, 'mobile' => $mobile, 'address' => $address]);

            $this->activity->record(
                ActivityAction::CustomerCreated,
                "Added customer {$name} (".MobileNumber::format($mobile).')',
                $customer,
                ActivityChanges::between([], ActivitySnapshot::customer($customer)),
            );

            return $customer;
        }

        if ($customer->is_admin) {
            return $customer;
        }

        $changes = array_filter([
            'name' => $customer->name !== $name ? $name : null,
            'address' => $address !== null && $customer->address !== $address ? $address : null,
        ]);

        if ($changes) {
            $before = ActivitySnapshot::customer($customer);
            $this->customers->updateDetails($customer, $changes);
            $logged = ActivityChanges::between($before, ActivitySnapshot::customer($customer));

            $this->activity->record(
                ActivityAction::CustomerUpdated,
                "Changed customer {$customer->name}: ".ActivityChanges::summary($logged),
                $customer,
                $logged,
            );
        }

        return $customer;
    }
}
