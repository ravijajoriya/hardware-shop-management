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

    public function updatePassword(User $user, string $password): void
    {
        // Hashed by the model's "hashed" cast.
        $user->forceFill(['password' => $password])->save();
    }

    /**
     * Log the user out everywhere except with the token $keepTokenId.
     *
     * @return int How many logins were ended.
     */
    public function deleteTokensExcept(User $user, ?int $keepTokenId): int
    {
        return $user->tokens()
            ->when($keepTokenId, fn ($tokens) => $tokens->whereKeyNot($keepTokenId))
            ->delete();
    }
}
