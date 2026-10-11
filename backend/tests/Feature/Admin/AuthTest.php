<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_log_in_and_receives_a_token(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->postJson('/api/admin/login', [
            'email' => $admin->email,
            'password' => 'password',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email']])
            ->assertJsonPath('user.email', $admin->email);

        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_login_fails_with_a_wrong_password(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->postJson('/api/admin/login', [
            'email' => $admin->email,
            'password' => 'wrong-password',
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_non_admin_cannot_log_in(): void
    {
        $user = User::factory()->create();

        $response = $this->postJson('/api/admin/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_login_requires_email_and_password(): void
    {
        $this->postJson('/api/admin/login', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_login_is_rate_limited(): void
    {
        $admin = User::factory()->admin()->create();

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/admin/login', [
                'email' => $admin->email,
                'password' => 'wrong-password',
            ])->assertUnprocessable();
        }

        $this->postJson('/api/admin/login', [
            'email' => $admin->email,
            'password' => 'password',
        ])->assertTooManyRequests();
    }

    public function test_admin_can_fetch_their_profile(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('admin')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/admin/me')
            ->assertOk()
            ->assertExactJson([
                'id' => $admin->id,
                'name' => $admin->name,
                'email' => $admin->email,
            ]);
    }

    public function test_admin_routes_require_a_token(): void
    {
        $this->getJson('/api/admin/me')->assertUnauthorized();
        $this->postJson('/api/admin/logout')->assertUnauthorized();
    }

    public function test_non_admin_token_is_rejected_on_admin_routes(): void
    {
        $token = User::factory()->create()->createToken('test')->plainTextToken;

        $this->withToken($token)->getJson('/api/admin/me')->assertForbidden();
    }

    public function test_logout_revokes_only_the_current_token(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('admin')->plainTextToken;
        $otherDevice = $admin->createToken('admin');

        $this->withToken($token)->postJson('/api/admin/logout')->assertNoContent();

        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $otherDevice->accessToken->id]);

        // The guard caches the resolved user for the lifetime of the test app.
        Auth::forgetGuards();

        $this->withToken($token)->getJson('/api/admin/me')->assertUnauthorized();
    }
}
