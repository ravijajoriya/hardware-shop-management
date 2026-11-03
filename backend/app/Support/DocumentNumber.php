<?php

namespace App\Support;

/**
 * Yearly numbered series for the shop's papers: INV-2026-0001 for bills, RET-2026-0001 for
 * returns. The year follows the shop's clock, so one made just after midnight on 1 January
 * starts the new year's series.
 */
final class DocumentNumber
{
    public static function prefix(string $series): string
    {
        return $series.'-'.now(config('shop.timezone'))->year.'-';
    }

    /**
     * The number after $last in the series that $prefix starts, or the first one.
     */
    public static function next(string $prefix, ?string $last): string
    {
        $next = $last ? (int) substr($last, strlen($prefix)) + 1 : 1;

        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }
}
