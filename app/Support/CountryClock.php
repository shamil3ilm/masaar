<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\Carbon;

/**
 * The civil clock a country keeps: what "today" and "this year" mean there.
 *
 * A date printed on a document or a period a counter restarts at is a civil
 * statement, read on the clock of the country the organization is registered
 * in. An instant - when something was signed, when a retry is due - is UTC
 * and does not belong here.
 *
 * Saudi Arabia is the default because it was the only country until the UAE
 * arrived, and an organization with no country has always been on its clock.
 */
final class CountryClock
{
    /** ISO 3166-1 alpha-2 country => IANA time zone. */
    private const TIME_ZONES = [
        'SA' => 'Asia/Riyadh',
        'AE' => 'Asia/Dubai',
    ];

    private const DEFAULT_TIME_ZONE = 'Asia/Riyadh';

    public static function timeZone(?string $country): string
    {
        return self::TIME_ZONES[strtoupper((string) $country)] ?? self::DEFAULT_TIME_ZONE;
    }

    public static function now(?string $country): Carbon
    {
        return Carbon::now(self::timeZone($country));
    }
}
