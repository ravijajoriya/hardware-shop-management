<?php

namespace App\Services\Admin;

use App\Enums\ActivityAction;
use App\Models\User;
use App\Repositories\UserRepository;
use App\Services\ActivityLogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

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

    /**
     * Set a new password and log the admin out on every other phone and computer, so anyone
     * who knew the old password, or kept a login, is shut out. This device stays logged in.
     *
     * @return int How many other devices were logged out.
     */
    public function changePassword(User $user, string $password): int
    {
        $token = $user->currentAccessToken();
        $keepId = $token instanceof PersonalAccessToken ? $token->getKey() : null;

        return DB::transaction(function () use ($user, $password, $keepId) {
            $this->users->updatePassword($user, $password);
            $others = $this->users->deleteTokensExcept($user, $keepId);

            $this->activity->record(
                ActivityAction::PasswordChanged,
                'Changed the password'.($others ? ' and logged out '.$others.' other '.Str::plural('device', $others) : ''),
                actor: $user,
            );

            return $others;
        });
    }
}
