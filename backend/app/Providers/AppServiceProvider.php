<?php

namespace App\Providers;

use App\Repositories\CustomerRepository;
use Closure;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // {customer} in a route only matches users who are customers, never an admin.
        Route::bind('customer', fn (string $value) => $this->app->make(CustomerRepository::class)->findOrFail((int) $value));

        $this->configureRateLimits();
    }

    /**
     * The limiters that routes name with "throttle:…". The numbers are in config/shop.php.
     * Each limiter counts on its own, so a heavy request counts towards both "api" and its own.
     */
    private function configureRateLimits(): void
    {
        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute($this->limit('login_per_minute'))
                ->by('ip-minute|'.$request->ip())
                ->response($this->tooMany('login attempts')),
            Limit::perHour($this->limit('login_per_hour'))
                ->by('ip-hour|'.$request->ip())
                ->response($this->tooMany('login attempts')),
            // Guessing one account's password from many places at once.
            Limit::perHour($this->limit('login_per_email_per_hour'))
                ->by('email|'.Str::lower(trim((string) $request->input('email'))))
                ->response($this->tooMany('login attempts')),
        ]);

        foreach (['api', 'imports', 'uploads', 'pdfs', 'reports'] as $name) {
            RateLimiter::for($name, fn (Request $request) => Limit::perMinute($this->limit($name))
                ->by('user|'.($request->user()?->id ?? $request->ip()))
                ->response($this->tooMany('requests')));
        }

        // Opened in the customer's browser, so the answer is plain text rather than JSON.
        RateLimiter::for('shared-bill', fn (Request $request) => Limit::perMinute($this->limit('shared_bill'))
            ->by($request->ip())
            ->response(fn (Request $request, array $headers) => response(
                'Too many requests for this bill. Please wait '.$this->wait($headers).' and open the link again.',
                429,
                $headers + ['Content-Type' => 'text/plain; charset=utf-8'],
            )));
    }

    private function limit(string $name): int
    {
        return (int) config("shop.rate_limits.{$name}");
    }

    private function tooMany(string $what): Closure
    {
        return fn (Request $request, array $headers) => response()->json(
            ['message' => "Too many {$what}. Please wait {$this->wait($headers)} and try again."],
            429,
            $headers,
        );
    }

    /**
     * "45 seconds" or "12 minutes", from the Retry-After header.
     *
     * @param  array<string, mixed>  $headers
     */
    private function wait(array $headers): string
    {
        $seconds = max(1, (int) ($headers['Retry-After'] ?? 60));

        if ($seconds < 60) {
            return $seconds.' '.Str::plural('second', $seconds);
        }

        $minutes = (int) ceil($seconds / 60);

        return $minutes.' '.Str::plural('minute', $minutes);
    }
}
