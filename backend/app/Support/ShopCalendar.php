<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Days by the shop's calendar (config shop.timezone), as the UTC moments times are stored in.
 * Just after midnight in India is still the day before in UTC, so a day has to be turned
 * into moments before bills, payments or log entries can be matched to it.
 */
final class ShopCalendar
{
    /**
     * The first moment of the day (Y-m-d), in UTC.
     */
    public static function start(string $day): Carbon
    {
        return Carbon::parse($day, config('shop.timezone'))->startOfDay()->utc();
    }

    /**
     * The first moment after the day (Y-m-d), in UTC: everything before it is on or before that day.
     */
    public static function after(string $day): Carbon
    {
        return Carbon::parse($day, config('shop.timezone'))->addDay()->startOfDay()->utc();
    }
}
