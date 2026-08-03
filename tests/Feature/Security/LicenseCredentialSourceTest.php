<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Domains\Licensing\Http\Middleware\ValidateLicense;
use Illuminate\Http\Request;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Regression guard for C-3 — the licence middleware accepted the API key AND
 * secret from the query string, leaking a complete credential pair into access
 * logs, proxy logs, CDN logs, APM traces, browser history and Referer headers.
 */
class LicenseCredentialSourceTest extends TestCase
{
    public function test_api_key_is_not_read_from_the_query_string(): void
    {
        $request = Request::create('/v1/invoices?api_key=cpay_leaked_key', 'GET');

        $this->assertNull($this->extract('extractApiKey', $request));
    }

    public function test_api_secret_is_not_read_from_the_query_string(): void
    {
        $request = Request::create('/v1/invoices?api_secret=leaked_secret', 'GET');

        $this->assertNull($this->extract('extractApiSecret', $request));
    }

    public function test_credentials_are_read_from_dedicated_headers(): void
    {
        $request = Request::create('/v1/invoices', 'GET', server: [
            'HTTP_X_API_KEY' => 'cpay_key',
            'HTTP_X_API_SECRET' => 'secret',
        ]);

        $this->assertSame('cpay_key', $this->extract('extractApiKey', $request));
        $this->assertSame('secret', $this->extract('extractApiSecret', $request));
    }

    public function test_credentials_are_read_from_the_bearer_pair(): void
    {
        $request = Request::create('/v1/invoices', 'GET', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.base64_encode('cpay_key:secret'),
        ]);

        $this->assertSame('cpay_key', $this->extract('extractApiKey', $request));
        $this->assertSame('secret', $this->extract('extractApiSecret', $request));
    }

    /**
     * Headers must win outright: a query parameter cannot supplement or
     * override a header-supplied credential.
     */
    public function test_query_string_cannot_supply_the_missing_half_of_a_pair(): void
    {
        $request = Request::create('/v1/invoices?api_secret=leaked_secret', 'GET', server: [
            'HTTP_X_API_KEY' => 'cpay_key',
        ]);

        $this->assertSame('cpay_key', $this->extract('extractApiKey', $request));
        $this->assertNull($this->extract('extractApiSecret', $request));
    }

    private function extract(string $method, Request $request): ?string
    {
        $reflection = new ReflectionMethod(ValidateLicense::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke(app(ValidateLicense::class), $request);
    }
}
