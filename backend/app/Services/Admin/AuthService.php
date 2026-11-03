<?php

namespace App\Services\Admin;

use App\Enums\ActivityAction;
use App\Models\User;
use App\Repositories\UserRepository;
use App\Services\ActivityLogService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthService
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly ActivityLogService $activity,
    ) {}

    /**
     * Verify an admin's credentials and issue an API token. Every try is logged, so
     * someone guessing passwords shows up in the activity log.
     *
     * @return array{token: string, user: User}
     *
     * @throws ValidationException
     */
    public function login(string $email, string $password): array
    {
        $user = $this->users->findByEmail($email);

        $failure = match (true) {
            ! $user => 'No account has this email',
            ! $user->is_admin => 'The account is not an admin',
            ! Hash::check($password, $user->password) => 'Wrong password',
            default => null,
        };

        if ($failure) {
            // Logged against no one: whoever tried, it was not necessarily the account's owner.
            $this->activity->record(ActivityAction::LoginFailed, "Failed login for {$email}", details: [
                ['label' => 'Email tried', 'value' => $email],
                ['label' => 'Why it failed', 'value' => $failure],
            ]);

            // Non-admins get the same error as a wrong password, so the response
            // does not reveal which accounts exist.
            throw ValidationException::withMessages([
                'email' => [__('auth.failed')],
            ]);
        }

        $this->activity->record(ActivityAction::LoggedIn, 'Logged in', actor: $user);

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
        $this->activity->record(ActivityAction::LoggedOut, 'Logged out', actor: $user);

        $user->currentAccessToken()->delete();
    }
}
