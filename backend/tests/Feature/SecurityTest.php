<?php

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\User;
use App\Providers\AppServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Admin\Concerns\MakesBills;
use Tests\TestCase;

class SecurityTest extends TestCase
{
    use MakesBills;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        TrustProxies::flushState();

        parent::tearDown();
    }

    public function test_every_answer_carries_the_safety_headers(): void
    {
        $this->get('/up')
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertHeaderMissing('Strict-Transport-Security');

        $this->getJson('/api/admin/me')
            ->assertUnauthorized()
            ->assertHeader('X-Frame-Options', 'DENY');

        // Browsers that reached the app over HTTPS are told to keep using it.
        $this->get('https://localhost/up')->assertHeader('Strict-Transport-Security', 'max-age=31536000');
    }

    public function test_laravels_welcome_page_with_its_version_is_gone(): void
    {
        $this->get('/')->assertNotFound()->assertDontSee(app()->version());
    }

    public function test_opening_a_bill_link_sets_no_cookies_and_starts_no_session(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->postBill('Ramesh Kumar', '9876543210', [[$this->itemInStock(), 1]])->assertCreated();
        $this->app['auth']->forgetGuards();

        $response = $this->get('/bill/'.Bill::query()->value('share_token'))->assertOk();

        $this->assertSame([], $response->headers->getCookies());
        $this->assertDatabaseCount('sessions', 0);
    }

    public function test_a_login_lasts_thirty_days(): void
    {
        $token = User::factory()->admin()->create()->createToken('admin')->plainTextToken;

        $this->travel(29)->days();
        $this->withToken($token)->getJson('/api/admin/me')->assertOk();

        $this->app['auth']->forgetGuards();
        $this->travel(2)->days();
        $this->withToken($token)->getJson('/api/admin/me')->assertUnauthorized();
    }

    public function test_a_made_up_forwarded_address_is_ignored_without_a_trusted_proxy(): void
    {
        config(['shop.rate_limits.login_per_minute' => 1]);

        $this->tryLoginFrom('1.1.1.1')->assertUnprocessable();

        // Still the same visitor, so still limited.
        $this->tryLoginFrom('2.2.2.2')->assertTooManyRequests();
    }

    public function test_behind_a_trusted_proxy_each_visitor_is_counted_on_their_own(): void
    {
        config(['shop.rate_limits.login_per_minute' => 1, 'shop.trusted_proxies' => '*']);
        $this->app->getProvider(AppServiceProvider::class)->boot();

        $this->tryLoginFrom('1.1.1.1')->assertUnprocessable();
        $this->tryLoginFrom('2.2.2.2')->assertUnprocessable();
        $this->tryLoginFrom('1.1.1.1')->assertTooManyRequests();
    }

    private function tryLoginFrom(string $forwardedFor): TestResponse
    {
        return $this->withHeader('X-Forwarded-For', $forwardedFor)
            ->postJson('/api/admin/login', ['email' => 'someone@example.com', 'password' => 'wrong-password']);
    }
}
