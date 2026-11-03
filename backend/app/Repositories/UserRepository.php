<?php

namespace App\Repositories;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class UserRepository
{
    public function findByEmail(string $email): ?User
    {
        return User::where('email', $email)->first();
    }

    /**
     * @return Collection<int, User>
     */
    public function admins(): Collection
    {
        return User::where('is_admin', true)->orderBy('name')->get(['id', 'name']);
    }
}
