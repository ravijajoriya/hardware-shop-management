<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Shop details
    |--------------------------------------------------------------------------
    |
    | Printed at the top of every bill and used in WhatsApp messages to customers.
    |
    */

    'name' => env('SHOP_NAME', env('APP_NAME', 'Hardware Shop')),

    'address' => env('SHOP_ADDRESS'),

    'phone' => env('SHOP_PHONE'),

    /*
    |--------------------------------------------------------------------------
    | Shop timezone
    |--------------------------------------------------------------------------
    |
    | Decides which day a bill or payment belongs to, for "today's sales" and
    | for the year in each bill number.
    |
    */

    'timezone' => env('SHOP_TIMEZONE', 'Asia/Kolkata'),

    /*
    |--------------------------------------------------------------------------
    | Rate limits
    |--------------------------------------------------------------------------
    |
    | How many requests are allowed before the app asks to wait, so that a mistake, a stolen
    | login or someone guessing passwords cannot overload a small server. Searches in the app
    | wait for typing to pause, so normal use stays far below these.
    |
    */

    'rate_limits' => [
        // Every request of a logged-in admin, a minute.
        'api' => 60,

        // On top of that, a minute, for the requests that take the server the most work.
        'imports' => 5, // Excel import: template, check and import
        'uploads' => 10, // product photos, each request up to 10 of them
        'pdfs' => 10, // bill PDFs
        'reports' => 20, // sales reports

        // Logging in: from one IP address, and for one email from anywhere.
        'login_per_minute' => 5,
        'login_per_hour' => 20,
        'login_per_email_per_hour' => 30,

        // The bill link sent to customers on WhatsApp, a minute from one IP address.
        'shared_bill' => 10,

        // Tries at changing the password, a minute per admin.
        'password' => 5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Trusted proxies
    |--------------------------------------------------------------------------
    |
    | Set "*" when the app runs behind a hosting platform's own front server (Render, Railway,
    | Cloudflare and the like), so it sees each visitor's real IP address. Leave it empty when
    | visitors reach the server directly: then nobody can fake their address.
    |
    */

    'trusted_proxies' => env('TRUSTED_PROXIES'),

    /*
    |--------------------------------------------------------------------------
    | First admin account
    |--------------------------------------------------------------------------
    |
    | Used once by AdminUserSeeder. On a live server both must be set (the password at least
    | 10 characters), so the app never goes online with the example login from the README.
    | Remove them from .env once the account exists.
    |
    */

    'admin' => [
        'name' => env('ADMIN_NAME', 'Admin'),
        'email' => env('ADMIN_EMAIL'),
        'password' => env('ADMIN_PASSWORD'),
    ],

];
