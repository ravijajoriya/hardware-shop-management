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

];
