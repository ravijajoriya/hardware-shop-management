<?php

namespace App\Providers;

use App\Repositories\CustomerRepository;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

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
    }
}
