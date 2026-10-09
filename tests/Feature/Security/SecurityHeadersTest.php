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

    /**
     * A version number tells an attacker which published vulnerabilities to
     * try first, and nothing legitimate reads it. PHP announces its own on
     * every response unless expose_php is off, which it is not in this image.
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
