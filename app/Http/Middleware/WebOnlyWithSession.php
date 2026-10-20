<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Pipeline;
use Illuminate\Routing\Router;
use Symfony\Component\HttpFoundation\Response;

/**
 * The web middleware, for a request that already has a session - and none
 * of it for one that does not.
 *
 * On the fallback route (any unknown address), which only ever answers 404.
 * A request carrying the session cookie gets the whole web group, so the
 * 404 knows who is signed in and leads them back to their dashboard. One
 * without it - a crawler, a first visit - gets the visitor's 404 with no
 * session started: no Set-Cookie and, with sessions in the database, no row
 * written for every address a crawler guesses.
 */
final class WebOnlyWithSession
{
    public function __construct(private readonly Router $router) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->cookies->has((string) config('session.cookie'))) {
            return $next($request);
        }

        return (new Pipeline(app()))
            ->send($request)
            ->through($this->router->resolveMiddleware(['web']))
            ->then($next);
    }
}
