<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class AdminUserSeeder extends Seeder
{
    /**
     * Create the default admin account if it does not exist yet.
     */
    public function run(): void
    {
        $admin = User::firstOrNew(['email' => 'admin@example.com']);

        // Leave an existing account alone so re-seeding never resets its password.
        if ($admin->exists) {
            return;
        }

        $admin->forceFill([
            'name' => 'Admin',
            'password' => 'password',
            'email_verified_at' => now(),
            'is_admin' => true,
        ])->save();
    }
}
