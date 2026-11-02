<?php

use App\Http\Controllers\SharedBillController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// A bill's private PDF link, sent to the customer on WhatsApp. It needs no login, so the token
// in it is long and random, and repeated tries from one place are slowed down.
Route::get('/bill/{bill:share_token}', [SharedBillController::class, 'show'])
    ->where('bill', '[A-Za-z0-9]{40}')
    ->middleware('throttle:30,1')
    ->name('bills.shared');
