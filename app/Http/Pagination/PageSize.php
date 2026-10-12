<?php

declare(strict_types=1);

namespace App\Http\Pagination;

use Illuminate\Http\Request;

/**
 * How many rows a request may ask for.
 *
 * Every endpoint that read a page size read it straight off the query string,
 * and three of them did nothing to it at all. Two values do something a
 * caller is unlikely to want:
 *
 *   per_page=0 returns the whole table. Laravel does not divide by it, it
 *   treats it as no limit - so the one parameter that exists to bound a
 *   response was also the way to remove the bound. On an authenticated
 *   endpoint that is every invoice in the organization in one answer.
 *
 *   per_page=-1 reaches MySQL as LIMIT -1, which is a syntax error, so the
 *   request is a 500 rather than a 422.
 *
 * Both were reachable on /api/v1/invoices and the webhook delivery log, where
 * per_page was cast to int and passed on. The licence list clamped the upper
 * bound and not the lower, which stops the large answer and not either of
 * these.
 *
 * Clamped rather than rejected. A page size is not something a caller states
 * an intent about - it is a convenience - and failing a request over it would
 * turn a harmless typo into an error the caller has to handle.
 *
 * Nonsense gets the default, not the nearest number. Clamping 0 up to 1 is
 * arithmetically tidy and answers an absurd request with a page of one row,
 * which a caller then has to page through the whole table to read. Only a
 * too-large request is clamped, because that one does say something
 * meaningful - as many as you will give me.
 */
final class PageSize
{
    /**
     * The most rows any endpoint will serve in one page unless it says
     * otherwise. High enough to be useful to a client paging through a day's
     * invoices, low enough that one request is not a table dump.
     */
    public const MAX = 100;

    public static function fromRequest(
        Request $request,
        int $default,
        int $max = self::MAX,
        string $key = 'per_page',
    ): int {
        $asked = $request->query($key);

        // Anything that is not a usable positive number gets the default
        // rather than the nearest number. per_page=abc casts to 0, and
        // clamping 0 up to 1 would answer a nonsensical request with a page
        // of one row and make a caller walk the whole table to read it. The
        // default is what was meant in every one of these cases.
        if (! is_numeric($asked) || (int) $asked < 1) {
            return $default;
        }

        // Only a genuine, too-large request is clamped, because here the
        // caller did say something meaningful: as many as possible.
        return min((int) $asked, $max);
    }
}
