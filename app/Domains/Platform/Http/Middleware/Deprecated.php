<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Middleware;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * deprecated:SINCE,SUNSET[,SUCCESSOR] - a route on its way out, as the
 * deprecation policy in docs/integration/API.md promises it is announced:
 * every answer carries
 *
 *   Deprecation: @<SINCE as a Unix time>   (RFC 9745)
 *   Sunset: <SUNSET as an HTTP date>       (RFC 8594), the day it stops
 *   Link: <SUCCESSOR>; rel="successor-version", when there is one
 *
 * and platform:openapi marks the operation deprecated with the same dates
 * (RouteReader::deprecation()). Dates are YYYY-MM-DD, midnight UTC.
 */
class Deprecated
{
    public function handle(Request $request, Closure $next, string $since, string $sunset, ?string $successor = null): Response
    {
        $response = $next($request);

        $response->headers->set('Deprecation', '@'.CarbonImmutable::parse($since, 'UTC')->startOfDay()->getTimestamp());
        $response->headers->set('Sunset', CarbonImmutable::parse($sunset, 'UTC')->startOfDay()->toRfc7231String());

        if ($successor !== null && $successor !== '') {
            $response->headers->set('Link', '<'.url($successor).'>; rel="successor-version"');
        }

        return $response;
    }
}
