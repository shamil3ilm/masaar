<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A redirect named rather than followed, for a form a script sent.
 *
 * A form with a file in it is sent by resources/js/file-field.js so its
 * upload can show progress. XMLHttpRequest follows a redirect by itself, out
 * of sight, and the page it lands on spends the flash message the person
 * should have read. Asked with X-Redirect-Manually, a redirect comes back as
 * {"redirect": url} and the page goes there itself, as a submission would.
 * Nothing else changes: the session, the errors and the old input are what
 * the redirect would have carried.
 */
class NameRedirectForScript
{
    public const HEADER = 'X-Redirect-Manually';

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->headers->has(self::HEADER) && $response instanceof RedirectResponse) {
            return new JsonResponse(['redirect' => $response->getTargetUrl()], 200, ['Cache-Control' => 'no-store']);
        }

        return $response;
    }
}
