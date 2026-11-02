<?php

namespace App\Repositories;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Customers are the users who are not admins.
 */
class CustomerRepository
{
    /**
     * Whoever has this mobile number, customer or not.
     */
    public function findByMobile(string $mobile): ?User
    {
        return User::where('mobile', $mobile)->first();
    }

    public function findOrFail(int $id): User
    {
        return User::customers()->findOrFail($id);
    }

    /**
     * Customers by name, each with how many bills they have, what they were billed and what they owe.
     *
     * @param  array{search?: string|null, with_due?: bool|null}  $filters
     * @return LengthAwarePaginator<int, User>
     */
    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->withTotals(User::customers())
            ->when($filters['search'] ?? null, function (Builder $query, string $search) {
                $query->where(function (Builder $query) use ($search) {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('mobile', 'like', "%{$search}%");
                });
            })
            ->when($filters['with_due'] ?? false, function (Builder $query) {
                $query->whereHas('bills', fn (Builder $bills) => $bills->where('due_amount', '>', 0));
            })
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * Add the bill count, total billed and total due to a customer already loaded.
     */
    public function loadTotals(User $customer): User
    {
        return $customer
            ->loadCount('bills')
            ->loadSum('bills as total_billed', 'total')
            ->loadSum('bills as total_due', 'due_amount');
    }

    /**
     * The customer's bills, newest first, and every payment they have made.
     */
    public function loadHistory(User $customer): User
    {
        return $customer->load([
            'bills' => fn ($query) => $query->latest('created_at')->latest('id'),
            'payments' => fn ($query) => $query->with('bill:id,bill_number')->latest('received_at')->latest('bill_payments.id'),
        ]);
    }

    /**
     * @param  array{name: string, mobile: string}  $attributes
     */
    public function create(array $attributes): User
    {
        return User::create($attributes);
    }

    /**
     * @param  array{name?: string, address?: string}  $details
     */
    public function updateDetails(User $customer, array $details): void
    {
        $customer->update($details);
    }

    /**
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    private function withTotals(Builder $query): Builder
    {
        return $query
            ->withCount('bills')
            ->withSum('bills as total_billed', 'total')
            ->withSum('bills as total_due', 'due_amount');
    }
}
