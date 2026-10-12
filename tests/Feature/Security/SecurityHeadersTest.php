<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The headers hold without a proxy in front.
 *
 * docker/nginx/default.conf sets four of these, so a production response
 * behind that proxy carried them and the application on its own carried
 * none. The dynamic scan in CI measures the application on its own - it runs
 * against php artisan serve - and reported thirteen warnings, most of which
 * were the proxy's absence rather than anything about the code. A scanner
 * whose findings are mostly about the environment is a scanner nobody reads.
 *
 * So they are set here too, and asserted here, which also means a deployment
 * that terminates somewhere other than this nginx config is not quietly
 * without them.
 */
class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function headers(): array
    {
        return [
            ['X-Frame-Options', 'DENY'],
            ['X-Content-Type-Options', 'nosniff'],
            ['Referrer-Policy', 'strict-origin-when-cross-origin'],
        ];
    }

    /**
     * The directives that have to be there, and what each one stops.
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function directives(): array
    {
        return [
            ["default-src 'self'", 'anything not named below would fall back to permitted'],
            ["script-src 'self'", 'an injected script tag or src would execute'],
            ["style-src 'self'", 'an injected stylesheet could read the page through selectors'],
            ["form-action 'self'", 'an injected form could post a session somewhere else'],
            ["frame-ancestors 'none'", 'the console could be framed and clickjacked'],
            ["base-uri 'self'", 'an injected base tag could redirect every relative URL'],
            ["object-src 'none'", 'a plugin object could execute'],
        ];
    }

    #[DataProvider('headers')]
    public function test_an_api_response_carries_them(string $header, string $value): void
    {
        $this->getJson('/api/health')->assertHeader($header, $value);
    }

    #[DataProvider('headers')]
    public function test_a_page_response_carries_them(string $header, string $value): void
    {
        $this->get('/login')->assertHeader($header, $value);
    }

    #[DataProvider('directives')]
    public function test_the_policy_carries_each_directive(string $directive, string $otherwise): void
    {
        $policy = (string) $this->get('/login')->headers->get('Content-Security-Policy');

        $this->assertStringContainsString($directive, $policy, 'Without '.$directive.', '.$otherwise.'.');
    }

    /**
     * And it permits nothing unsafe.
     *
     * This is the assertion that keeps the policy worth having. A policy with
     * 'unsafe-inline' in script-src stops almost nothing, and it is the first
     * thing anybody adds when a page breaks. It would not be needed today:
     * there is no inline script or style in any view, the four onsubmit
     * handlers are a delegated listener in the bundle, and Alpine is its CSP
     * build, which evaluates no attribute expressions. If one of those is
     * undone this fails, which is the point - the fix is the markup, not the
     * header.
     */
    public function test_the_policy_permits_nothing_unsafe(): void
    {
        $policy = (string) $this->get('/login')->headers->get('Content-Security-Policy');

        // One substring covers 'unsafe-inline', 'unsafe-eval' and
        // 'unsafe-hashes' alike. There is no safe use of any of them here.
        $this->assertStringNotContainsString('unsafe-', $policy,
            'The policy permits an unsafe- source, which is most of what it exists to prevent.');

        $this->assertStringNotContainsString('*', $policy,
            'The policy names a wildcard source.');

        // data: is for images and nothing else. A data: URI in script-src is
        // a way to execute an arbitrary payload, and in style-src a way to
        // load an arbitrary stylesheet; in img-src it is a picture.
        foreach (explode('; ', $policy) as $directive) {
            if (str_contains($directive, 'data:')) {
                $this->assertStringStartsWith('img-src ', $directive,
                    'data: is permitted by '.$directive.'. It belongs to img-src alone.');
            }
        }
    }

    /**
     * A version number tells an attacker which published vulnerabilities to
     * try first, and nothing legitimate reads it.
     *
     * The image does turn expose_php off and nginx hides it from FastCGI
     * responses, so a deployment never leaked it - but `php artisan serve`
     * does, and removing it from the Symfony header bag never touched it,
     * because PHP emits this one from the SAPI and it is not in the bag. The
     * middleware calls header_remove() as well now. The nightly scan reported
     * it on every page, which is how that was found.
     */
    public function test_no_version_is_announced(): void
    {
        foreach ([$this->getJson('/api/health'), $this->get('/login')] as $response) {
            $this->assertFalse(
                $response->headers->has('X-Powered-By'),
                'A response announced the PHP version.'
            );
        }
    }

    /**
     * Features no page here uses are denied rather than left to the browser's
     * default, which costs nothing and removes the surface if a page is ever
     * injected.
     */
    public function test_unused_browser_features_are_denied(): void
    {
        $policy = (string) $this->get('/login')->headers->get('Permissions-Policy');

        foreach (['camera', 'microphone', 'geolocation', 'payment'] as $feature) {
            $this->assertStringContainsString($feature.'=()', $policy, "{$feature} is not denied.");
        }
    }
}
