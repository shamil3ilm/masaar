<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Domains\Licensing\Http\Middleware\ValidateLicense;
use Illuminate\Http\Request;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Licence credentials are read from headers only.
 *
 * URLs are recorded by web servers, reverse proxies, CDNs, APM traces, browser
 * history and the Referer header. A credential in the query string therefore
 * lands in systems with far weaker access controls than the credential store —
 * and here it would be the key and the secret together.
 */
class LicenseCredentialSourceTest extends TestCase
{
    public function test_key_not_from_query(): void
    {
        $request = Request::create('/v1/invoices?api_key=cpay_leaked_key', 'GET');

        $this->assertNull($this->extract('extractApiKey', $request));
    }

    public function test_secret_not_from_query(): void
    {
        $request = Request::create('/v1/invoices?api_secret=leaked_secret', 'GET');

        $this->assertNull($this->extract('extractApiSecret', $request));
    }

    public function test_creds_from_headers(): void
    {
        $request = Request::create('/v1/invoices', 'GET', server: [
            'HTTP_X_API_KEY' => 'cpay_key',
            'HTTP_X_API_SECRET' => 'secret',
        ]);

        $this->assertSame('cpay_key', $this->extract('extractApiKey', $request));
        $this->assertSame('secret', $this->extract('extractApiSecret', $request));
    }

    public function test_creds_from_bearer(): void
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
    public function test_query_cannot_fill_gap(): void
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
