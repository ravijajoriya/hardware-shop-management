<?php

namespace Tests\Feature\Seeders;

use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

class AdminUserSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_on_a_developers_computer_it_creates_the_example_login(): void
    {
        (new AdminUserSeeder)->run();

        $admin = User::sole();
        $this->assertSame('admin@example.com', $admin->email);
        $this->assertTrue($admin->is_admin);
        $this->assertTrue(Hash::check('password', $admin->password));
    }

    public function test_on_a_live_server_it_uses_the_login_from_env(): void
    {
        $this->app['env'] = 'production';
        config(['shop.admin' => ['name' => 'Ravi', 'email' => 'owner@myshop.in', 'password' => 'a-long-password']]);

        (new AdminUserSeeder)->run();

        $admin = User::sole();
        $this->assertSame(['Ravi', 'owner@myshop.in', true], [$admin->name, $admin->email, $admin->is_admin]);
        $this->assertTrue(Hash::check('a-long-password', $admin->password));
    }

    public function test_a_live_server_refuses_a_missing_example_or_weak_login(): void
    {
        $this->app['env'] = 'production';

        $cases = [
            [null, null, 'Set ADMIN_EMAIL and ADMIN_PASSWORD'],
            ['admin@example.com', 'a-long-password', 'the example login is public'],
            ['owner@myshop.in', 'password', 'the example login is public'],
            ['owner@myshop.in', 'too-short', 'at least 10 characters'],
        ];

        foreach ($cases as [$email, $password, $message]) {
            config(['shop.admin.email' => $email, 'shop.admin.password' => $password]);

            try {
                (new AdminUserSeeder)->run();
                $this->fail("Seeding should have been refused for {$email}.");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }

        $this->assertSame(0, User::count());
    }

    public function test_an_existing_admin_is_left_alone(): void
    {
        $admin = User::factory()->admin()->create(['email' => 'admin@example.com', 'password' => 'changed-later']);

        (new AdminUserSeeder)->run();

        $this->assertTrue(Hash::check('changed-later', $admin->fresh()->password));
    }
}
