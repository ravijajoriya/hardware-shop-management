<?php

namespace App\Services\Admin;

use App\Models\User;
use App\Repositories\UserRepository;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthService
{
    public function __construct(private readonly UserRepository $users) {}

    /**
     * Verify an admin's credentials and issue an API token.
     *
     * @return array{token: string, user: User}
     *
     * @throws ValidationException
     */
    public function login(string $email, string $password): array
    {
        $user = $this->users->findByEmail($email);

        // Non-admins get the same error as a wrong password, so the response
        // does not reveal which accounts exist.
        if (! $user || ! $user->is_admin || ! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => [__('auth.failed')],
            ]);
        }

        return [
            'token' => $user->createToken('admin')->plainTextToken,
            'user' => $user,
        ];
    }

    /**
     * Revoke the token the admin is currently using.
     */
    public function logout(User $user): void
    {
        $user->currentAccessToken()->delete();
    }
}
