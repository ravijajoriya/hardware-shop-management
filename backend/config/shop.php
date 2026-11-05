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
    ],

];
