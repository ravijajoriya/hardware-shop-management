<?php

namespace Tests\Feature\Admin;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ChangePasswordTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create(['password' => 'old-password-1']);
        $this->token = $this->admin->createToken('admin')->plainTextToken;
    }

    private function change(string $current, string $new, ?string $again = null): TestResponse
    {
        return $this->withToken($this->token)->putJson('/api/admin/password', [
            'current_password' => $current,
            'password' => $new,
            'password_confirmation' => $again ?? $new,
        ]);
    }

    public function test_guests_cannot_change_a_password(): void
    {
        $this->putJson('/api/admin/password', [])->assertUnauthorized();
    }

    public function test_changing_the_password_logs_out_every_other_device(): void
    {
        $phone = $this->admin->createToken('admin')->plainTextToken;
        $this->admin->createToken('admin');

        $this->change('old-password-1', 'new-password-22')
            ->assertOk()
            ->assertJsonPath('data.other_devices_logged_out', 2);

        $this->assertTrue(Hash::check('new-password-22', $this->admin->fresh()->password));
        $this->assertSame(1, $this->admin->tokens()->count());
        $this->assertSame(
            'Changed the password and logged out 2 other devices',
            ActivityLog::query()->where('action', 'auth.password_changed')->value('description'),
        );

        // This device stays logged in; the others are out.
        $this->app['auth']->forgetGuards();
        $this->withToken($this->token)->getJson('/api/admin/me')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($phone)->getJson('/api/admin/me')->assertUnauthorized();

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/admin/login', ['email' => $this->admin->email, 'password' => 'new-password-22'])->assertOk();
    }

    public function test_the_current_password_is_needed_and_the_new_one_is_checked(): void
    {
        $this->change('not-my-password', 'new-password-22')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['current_password' => 'This is not your current password.']);

        $this->change('old-password-1', 'short')
            ->assertJsonValidationErrors(['password' => 'Use at least 10 characters.']);

        $this->change('old-password-1', 'new-password-22', 'new-password-23')
            ->assertJsonValidationErrors(['password' => 'The two new passwords are different.']);

        $this->change('old-password-1', 'old-password-1')
            ->assertJsonValidationErrors(['password' => 'Choose a password different from the current one.']);

        $this->assertTrue(Hash::check('old-password-1', $this->admin->fresh()->password));
    }

    public function test_tries_at_changing_the_password_are_limited(): void
    {
        config(['shop.rate_limits.password' => 2]);

        $this->change('guess-one', 'new-password-22')->assertUnprocessable();
        $this->change('guess-two', 'new-password-22')->assertUnprocessable();
        $this->change('old-password-1', 'new-password-22')->assertTooManyRequests();
    }
}
