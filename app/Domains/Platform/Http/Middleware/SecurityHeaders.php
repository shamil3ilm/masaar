<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The response headers that do not depend on a reverse proxy being in front.
 *
 * docker/nginx/default.conf already sets X-Frame-Options,
 * X-Content-Type-Options, X-XSS-Protection and Referrer-Policy, so a
 * production response behind that proxy carries them. Set here as well, for
 * two reasons: a deployment that terminates somewhere other than this nginx
 * config gets them anyway, and a scan of the application on its own - which
 * is what the dynamic scan in CI does, against php artisan serve - measures
 * the application rather than the proxy. Thirteen warnings from that scan
 * were mostly the proxy's absence rather than anything about the code, and a
 * scanner that reports the environment is a scanner nobody reads.
 *
 * nginx's `always` means its value wins where both are set, so this does not
 * fight it.
 *
 * What is NOT here, and why:
 *
 * Content-Security-Policy. The consoles load Tailwind's Play CDN, which
 * compiles stylesheets in the browser and needs script-src 'unsafe-eval' and
 * style-src 'unsafe-inline' - a policy permitting both protects against
 * little, and one without them breaks every page. The answer is to build the
 * stylesheet rather than ship a compiler to the browser, and that is a
 * frontend change rather than a header. Until then a policy here would be
 * decoration.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // PHP announces its own version on every response unless expose_php is
        // off, which it is not in this image. A version number tells an
        // attacker which published vulnerabilities to try first, and nothing
        // legitimate reads it.
        $response->headers->remove('X-Powered-By');
        $response->headers->remove('Server');

        foreach ([
            // Deny rather than SAMEORIGIN: nothing here is framed, including
            // by itself, and a console with a login form is what clickjacking
            // is for.
            'X-Frame-Options' => 'DENY',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            // No page here uses a camera, a microphone, geolocation or
            // payment, so the browser is told not to grant them - which costs
            // nothing and removes the surface if a page is ever injected.
            'Permissions-Policy' => 'accelerometer=(), camera=(), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), payment=(), usb=()',
        ] as $header => $value) {
            if (! $response->headers->has($header)) {
                $response->headers->set($header, $value);
            }
        }

        return $response;
    }
}
