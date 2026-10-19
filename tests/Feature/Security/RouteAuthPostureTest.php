<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Asserts the auth posture of every registered Blade route.
 *
 * C-1 and C-2 both existed because a route group was registered without
 * middleware and nothing noticed. Per-route tests only cover the routes someone
 * remembered to write a test for; this sweeps the router itself, so a new
 * unguarded route fails here the moment it is added.
 *
 * Adding a route to PUBLIC_ROUTES is the deliberate act of publishing it.
 */
class RouteAuthPostureTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Routes intentionally reachable without a session, with the reason.
     */
    private const PUBLIC_ROUTES = [
        '/' => 'Marketing landing page',
        // Anyone may write to us before they have an account. CSRF, a
        // throttle and ContactGuard (honeypot, time trap, per-address and
        // per-email limits, link and keyword checks) stand in for a session.
        'contact' => 'Home page contact form submission',
        'login' => 'Credential entry form',
        // Which language the pages are in; it changes nothing else.
        'locale' => 'Interface language, offered before sign-in',
        'privacy' => 'Privacy notice',
        'terms' => 'Terms of service, accepted at sign-up',
        // A business signing itself up, and getting back in after a forgotten
        // password: before there is anyone to sign in as.
        'register' => 'Sign-up form',
        'forgot-password' => 'Password reset request form',
        'reset-password/{token}' => 'Password reset form, from the emailed link',
        'reset-password' => 'Password reset submission, checked against the emailed token',
        // The invitation link, and creating the account it is for: the token
        // is the credential, and it is hashed, single-use and expiring.
        'invitations/{token}' => 'Invitation landing page, from the emailed link',
        'invitations/{token}/join' => 'Creating the invited account, with the emailed token',
        'invitations/console/{token}' => 'Platform admin invitation landing page, from the emailed link',
        'invitations/console/{token}/join' => 'Creating the invited admin account, with the emailed token',
        // An issued invoice from the QR code on it: the 256-bit token is the
        // credential, revocable, rate limited, and opens that invoice only.
        'i/{token}' => 'An invoice opened from its link',
        'i/{token}/pdf' => 'That invoice\'s PDF, from the same link',
        // Reached after the password, before sign-in: someone with a second factor is not
        // authenticated until this checks the second factor. Each refuses
        // anyone without a fresh pending sign-in in the session.
        'login/two-factor' => 'Second factor, after the password',
        'login/two-factor/setup' => 'Platform admin enrolment, after the password',
        // Only marks the browser; enrolment still needs the password after it.
        'login/two-factor/enrol/{token}' => 'One-time enrolment link, before the password',
        'up' => 'Container health probe',
        // The look of the sign-in page has to load before anyone signs in,
        // and an organization's colours and logo are on every invoice it
        // hands out. Colours and images only; nothing a theme stores is
        // private.
        'theme/{scope}/stylesheet' => 'Theme colours, needed before sign-in',
        'theme/{scope}/logo' => 'Uploaded logo, shown on sign-in and on invoices',
        'theme/{scope}/favicon' => 'Uploaded favicon, needed before sign-in',
        // One CSS variable from six hex digits the route checks: no stored data.
        'theme/print-accent/{accent}' => 'An invoice template colour, for the print view',
        // What the home page shows anyone: a published testimonial's logo,
        // checked as an image on upload and served sandboxed. 404 otherwise.
        'website/testimonials/{testimonialId}/logo' => 'A published testimonial\'s logo, shown on the home page',
        // What search engines and link previews read: the public pages in
        // each language, the private areas they stay out of, and the
        // picture a shared link shows. Nothing private is in any of them.
        'sitemap.xml' => 'The public pages, in each language, for search engines',
        'robots.txt' => 'Where crawlers may go, and the sitemap',
        'website/share-image' => 'The picture a shared link to the site shows',
    ];

    public function test_all_web_routes_guarded(): void
    {
        $unguarded = [];

        foreach ($this->webRoutes() as $route) {
            $uri = $route->uri();

            if (array_key_exists($uri, self::PUBLIC_ROUTES)) {
                continue;
            }

            $middleware = $route->gatherMiddleware();

            if (! in_array('auth', $middleware, true)) {
                $unguarded[] = implode('|', $route->methods()).' /'.$uri;
            }
        }

        $this->assertSame([], $unguarded, sprintf(
            "These web routes carry no 'auth' middleware. Add authentication, or ".
            "declare them in %s::PUBLIC_ROUTES with a reason:\n  %s",
            self::class,
            implode("\n  ", $unguarded)
        ));
    }

    /**
     * The admin console additionally requires the cross-tenant privilege, and
     * the portal additionally requires session-derived tenant resolution.
     */
    public function test_admin_portal_gates_present(): void
    {
        foreach ($this->webRoutes() as $route) {
            $uri = '/'.$route->uri();
            $middleware = $route->gatherMiddleware();

            if (str_starts_with($uri, '/admin')) {
                $this->assertContains('platform.admin', $middleware, "{$uri} is missing platform.admin");
            }

            // /portal/switch deliberately runs without tenant resolution: it
            // clears the selection.
            if (str_starts_with($uri, '/portal') && $uri !== '/portal/switch') {
                $this->assertContains('portal.tenant', $middleware, "{$uri} is missing portal.tenant");
            }
        }
    }

    public function test_login_page_is_public(): void
    {
        $this->get('/login')->assertOk();
    }

    /**
     * @return list<RoutingRoute>
     */
    private function webRoutes(): array
    {
        return array_values(array_filter(
            Route::getRoutes()->getRoutes(),
            fn (RoutingRoute $route) => in_array('web', $route->gatherMiddleware(), true)
                || in_array('web', (array) ($route->getAction('middleware') ?? []), true)
        ));
    }
}
