<?php

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Admin\Concerns\MakesBills;
use Tests\TestCase;

class RateLimitTest extends TestCase
{
    use MakesBills;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Exact waiting times in the messages.
        $this->freezeTime();
    }

    private function login(string $password, string $email = 'owner@example.com', string $ip = '10.0.0.1'): TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson('/api/admin/login', ['email' => $email, 'password' => $password]);
    }

    public function test_logging_in_waits_after_a_few_tries_a_minute_from_one_place(): void
    {
        config(['shop.rate_limits.login_per_minute' => 3]);
        User::factory()->admin()->create(['email' => 'owner@example.com', 'password' => 'right-password']);

        foreach (range(1, 3) as $try) {
            $this->login('wrong-password')->assertUnprocessable();
        }

        // Even the right password has to wait now, so guessing gains nothing.
        $this->login('right-password')
            ->assertTooManyRequests()
            ->assertHeader('Retry-After', 60)
            ->assertJsonPath('message', 'Too many login attempts. Please wait 1 minute and try again.');

        // Somewhere else is not held up.
        $this->login('right-password', ip: '10.0.0.2')->assertOk();

        $this->travel(61)->seconds();
        $this->login('right-password')->assertOk();
    }

    public function test_logging_in_is_also_limited_by_the_hour(): void
    {
        config(['shop.rate_limits.login_per_hour' => 3]);

        foreach (range(1, 3) as $try) {
            $this->login('wrong-password')->assertUnprocessable();
            $this->travel(2)->minutes();
        }

        $this->login('wrong-password')
            ->assertTooManyRequests()
            ->assertJsonPath('message', 'Too many login attempts. Please wait 54 minutes and try again.');

        $this->travel(55)->minutes();
        $this->login('wrong-password')->assertUnprocessable();
    }

    public function test_guessing_one_accounts_password_from_many_places_is_limited(): void
    {
        config(['shop.rate_limits.login_per_email_per_hour' => 3]);

        foreach (['10.0.0.1', '10.0.0.2', '10.0.0.3'] as $ip) {
            $this->login('wrong-password', ip: $ip)->assertUnprocessable();
        }

        $this->login('wrong-password', email: 'OWNER@example.com ', ip: '10.0.0.4')->assertTooManyRequests();
        $this->login('wrong-password', email: 'someone@example.com', ip: '10.0.0.4')->assertUnprocessable();
    }

    public function test_each_admin_has_a_limit_on_all_their_requests(): void
    {
        config(['shop.rate_limits.api' => 3]);
        $owner = User::factory()->admin()->create();
        Sanctum::actingAs($owner);

        $this->getJson('/api/admin/me')->assertOk();
        $this->getJson('/api/admin/categories')->assertOk();
        $this->getJson('/api/admin/dashboard/summary')->assertOk();

        $this->getJson('/api/admin/me')
            ->assertTooManyRequests()
            ->assertJsonPath('message', 'Too many requests. Please wait 1 minute and try again.');

        Sanctum::actingAs(User::factory()->admin()->create());
        $this->getJson('/api/admin/me')->assertOk();

        Sanctum::actingAs($owner);
        $this->travel(61)->seconds();
        $this->getJson('/api/admin/me')->assertOk();
    }

    public function test_heavy_requests_have_a_limit_of_their_own(): void
    {
        Storage::fake('public');
        config([
            'shop.rate_limits.imports' => 1,
            'shop.rate_limits.uploads' => 1,
            'shop.rate_limits.pdfs' => 1,
            'shop.rate_limits.reports' => 1,
        ]);
        Sanctum::actingAs(User::factory()->admin()->create());
        $hammer = $this->itemInStock();
        $billId = $this->postBill('Ramesh Kumar', '9876543210', [[$hammer, 1]])->json('data.id');

        $heavy = [
            fn () => $this->get('/api/admin/products/import/template', ['Accept' => 'application/json']),
            fn () => $this->post(
                "/api/admin/products/{$hammer->product_id}/images",
                ['images' => [UploadedFile::fake()->image('front.jpg')]],
                ['Accept' => 'application/json'],
            ),
            fn () => $this->get("/api/admin/bills/{$billId}/pdf", ['Accept' => 'application/json']),
            fn () => $this->getJson('/api/admin/reports/sales'),
        ];

        foreach ($heavy as $request) {
            $this->assertNotSame(429, $request()->status());
            $request()->assertTooManyRequests();
        }

        // The rest of the app still works.
        $this->getJson('/api/admin/me')->assertOk();
    }

    public function test_the_bill_link_sent_to_customers_is_limited_from_one_place(): void
    {
        config(['shop.rate_limits.shared_bill' => 2]);
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->postBill('Ramesh Kumar', '9876543210', [[$this->itemInStock(), 1]])->assertCreated();
        $this->app['auth']->forgetGuards();

        $link = '/bill/'.Bill::query()->value('share_token');

        $this->get($link)->assertOk();
        $this->get($link)->assertOk();

        $this->get($link)
            ->assertTooManyRequests()
            ->assertHeader('Content-Type', 'text/plain; charset=utf-8')
            ->assertSeeText('Too many requests for this bill. Please wait 1 minute and open the link again.');

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.9'])->get($link)->assertOk();
    }

    public function test_the_leftover_user_route_is_gone(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson('/api/user')->assertNotFound();
    }
}
