<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Middleware;

use Closure;
use Illuminate\Foundation\Vite;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The response headers that do not depend on a reverse proxy being in front.
 *
 * docker/nginx/default.conf sets the same ones, so a production response
 * behind that proxy carries them. Set here as well, for two reasons: a
 * deployment that terminates somewhere other than this nginx config gets them
 * anyway, and a scan of the application on its own - which is what the
 * dynamic scan in CI does, against php artisan serve - measures the
 * application rather than the proxy.
 *
 * nginx's `always` means its value wins where both are set, so this does not
 * fight it. That cuts both ways and did: while nginx said SAMEORIGIN and this
 * said DENY, the stricter value never reached a browser in a deployment. Both
 * say DENY now.
 */
class SecurityHeaders
{
    /**
     * The content policy, as directives rather than one string.
     *
     * Worth setting now, and not before. Until the CDN tags were removed
     * these pages loaded Tailwind's Play CDN, which compiles stylesheets in
     * the browser and needs 'unsafe-eval' and 'unsafe-inline' - a policy
     * granting both protects against very little. They are built by Vite now,
     * no view fetches anything from another origin, no view carries an inline
     * <script> or an inline style, and the four inline onsubmit handlers are
     * a delegated listener in the bundle. Alpine is its CSP build, which
     * evaluates no attribute expressions, so not even 'unsafe-eval' is
     * needed.
     *
     * The result is a policy with no 'unsafe-' of any kind. Each line below
     * is only true because of one of those changes, so weakening any of them
     * means weakening this.
     *
     * data: is allowed for images and nothing else. An image cannot execute,
     * and a QR code is the obvious thing to inline.
     *
     * @var array<string, string>
     */
    private const POLICY = [
        'default-src' => "'self'",
        'script-src' => "'self'",
        'style-src' => "'self'",
        'img-src' => "'self' data:",
        'font-src' => "'self'",
        'connect-src' => "'self'",
        // Where a form may post. Everything here posts to itself, so an
        // injected form cannot send a session anywhere.
        'form-action' => "'self'",
        // The modern X-Frame-Options, and stricter: it covers every embedding
        // element rather than frames alone.
        'frame-ancestors' => "'none'",
        // Stops an injected <base> redirecting every relative URL on the page.
        'base-uri' => "'self'",
        'object-src' => "'none'",
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // PHP announces its own version unless expose_php is off. The image
        // turns it off (see Dockerfile) and nginx hides it from FastCGI
        // responses, so a deployment does not leak it - but `php artisan
        // serve` does, which is what the nightly scan reports.
        //
        // Removing it from the Symfony bag is not enough and never was: PHP
        // emits this one itself, from the SAPI, and it is not in the bag to
        // remove. header_remove() reaches the list PHP actually sends. Both
        // are kept, because a proxy or a later middleware could put it in the
        // bag too.
        $response->headers->remove('X-Powered-By');
        $response->headers->remove('Server');

        if (! headers_sent()) {
            header_remove('X-Powered-By');
        }

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
            'Content-Security-Policy' => $this->policy(),
        ] as $header => $value) {
            if (! $response->headers->has($header)) {
                $response->headers->set($header, $value);
            }
        }

        return $response;
    }

    /**
     * The policy, with the Vite dev server allowed when it is running.
     *
     * `npm run dev` serves the bundle from another port and injects its
     * client over a websocket, all of which a policy naming only 'self'
     * refuses. The alternative was to skip the header outside production,
     * which would mean the policy is never exercised until it reaches a
     * deployment - and a policy first tested in production is how pages break
     * there.
     *
     * The origin comes from Vite's own hot file rather than a setting, so it
     * cannot be pointed somewhere else by configuration, and it is added only
     * while that file exists - which is only while the dev server runs.
     */
    private function policy(): string
    {
        $policy = self::POLICY;

        if (($origin = $this->devServerOrigin()) !== null) {
            // font-src too: the bundled typefaces are served by the dev
            // server while it runs, like the stylesheet that declares them.
            foreach (['script-src', 'style-src', 'font-src', 'connect-src'] as $directive) {
                $policy[$directive] .= ' '.$origin;
            }

            // Vite's client reloads over a websocket on the same host.
            $policy['connect-src'] .= ' '.str_replace(['http://', 'https://'], ['ws://', 'wss://'], $origin);
        }

        $directives = [];

        foreach ($policy as $directive => $value) {
            $directives[] = $directive.' '.$value;
        }

        return implode('; ', $directives);
    }

    private function devServerOrigin(): ?string
    {
        $vite = app(Vite::class);

        if (! $vite->isRunningHot()) {
            return null;
        }

        $url = trim((string) @file_get_contents($vite->hotFile()));

        if ($url === '') {
            return null;
        }

        $parts = parse_url($url);

        if (! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        return $parts['scheme'].'://'.$parts['host']
            .(isset($parts['port']) ? ':'.$parts['port'] : '');
    }
}
