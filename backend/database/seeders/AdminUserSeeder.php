<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

class AdminUserSeeder extends Seeder
{
    /** The example login in the README, only ever used on a developer's own computer. */
    private const EXAMPLE_EMAIL = 'admin@example.com';

    private const EXAMPLE_PASSWORD = 'password';

    public const MIN_PASSWORD_LENGTH = 10;

    /**
     * Create the admin account if it does not exist yet. On a live server the email and
     * password must come from .env (ADMIN_EMAIL and ADMIN_PASSWORD); the example login is
     * refused there, since anyone can read it in the README.
     */
    public function run(): void
    {
        ['name' => $name, 'email' => $email, 'password' => $password] = config('shop.admin');

        if (app()->isProduction()) {
            $this->checkForLiveServer($email, $password);
        }

        $admin = User::firstOrNew(['email' => $email ?: self::EXAMPLE_EMAIL]);

        // Leave an existing account alone so re-seeding never resets its password.
        if ($admin->exists) {
            return;
        }

        $admin->forceFill([
            'name' => $name ?: 'Admin',
            'password' => $password ?: self::EXAMPLE_PASSWORD,
            'email_verified_at' => now(),
            'is_admin' => true,
        ])->save();
    }

    private function checkForLiveServer(?string $email, ?string $password): void
    {
        if (! $email || ! $password) {
            throw new RuntimeException('Set ADMIN_EMAIL and ADMIN_PASSWORD in .env before creating the admin account on a live server.');
        }

        if (strtolower($email) === self::EXAMPLE_EMAIL || $password === self::EXAMPLE_PASSWORD) {
            throw new RuntimeException('Choose your own admin email and password: the example login is public.');
        }

        if (mb_strlen($password) < self::MIN_PASSWORD_LENGTH) {
            throw new RuntimeException('Use an admin password of at least '.self::MIN_PASSWORD_LENGTH.' characters.');
        }
    }
}
