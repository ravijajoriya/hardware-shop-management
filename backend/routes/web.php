<?php

use App\Http\Controllers\SharedBillController;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

// The API is the app; the screens are the separate Vue app. Laravel's own welcome page is
// gone because it shows which Laravel version runs here.

// A bill's private PDF link, sent to the customer on WhatsApp. It needs no login, so the token
// in it is long and random, and repeated tries from one place are slowed down. It needs no
// session or cookies either, so opening it never writes anything.
Route::get('/bill/{bill:share_token}', [SharedBillController::class, 'show'])
    ->where('bill', '[A-Za-z0-9]{40}')
    ->middleware('throttle:shared-bill')
    ->withoutMiddleware([
        EncryptCookies::class,
        AddQueuedCookiesToResponse::class,
        StartSession::class,
        ShareErrorsFromSession::class,
        PreventRequestForgery::class,
    ])
    ->name('bills.shared');
