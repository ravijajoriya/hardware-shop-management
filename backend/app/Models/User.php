<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Admins log in with email and password. Customers are users too: they have a name and
 * mobile number, no password, and can never log in.
 */
#[Fillable(['name', 'email', 'mobile', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The bills made out to this user as a customer.
     *
     * @return HasMany<Bill, $this>
     */
    public function bills(): HasMany
    {
        return $this->hasMany(Bill::class, 'customer_id');
    }

    /**
     * Every payment the customer has made, across all their bills.
     *
     * @return HasManyThrough<BillPayment, Bill, $this>
     */
    public function payments(): HasManyThrough
    {
        return $this->hasManyThrough(BillPayment::class, Bill::class, 'customer_id');
    }

    /**
     * Only users who are customers, not admins.
     *
     * @param  Builder<User>  $query
     */
    public function scopeCustomers(Builder $query): void
    {
        $query->where('is_admin', false);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
        ];
    }
}
