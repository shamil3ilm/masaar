<?php

declare(strict_types=1);

namespace App\Domains\Compliance\FTA\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * UAE filing, only where a deployment has switched it on.
 *
 * The platform launches with ZATCA alone. The UAE code stays, so turning it
 * on is a setting and not a release, but until UAE_FTA_ENABLED=true its
 * routes answer as if they did not exist rather than accepting work for an
 * authority nobody has configured.
 */
class UaeEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless((bool) config('fta.enabled'), 404);

        return $next($request);
    }
}
