<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\HostResolver;
use App\Support\SafeFetch;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\PumpStream;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Fetching an address the application did not choose.
 *
 * SafeUrl decides which hosts may be contacted; SafeFetch performs the request
 * under limits that hold even when the answer is hostile. The limit that
 * matters most is refusing redirects: validating an address and then following
 * wherever it points defeats the validation, since an allowlisted host
 * answering 302 to 169.254.169.254 would be followed straight to the metadata
 * endpoint.
 *
 * The refusals below are asserted without a network round trip — a rejected
 * address never reaches the socket — and the rest against a faked client,
 * which sees the options a request was sent with. RedirectPolicyTest applies
 * the same options to a real server and a real redirect.
 */
class SafeFetchTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['security.revocation_hosts' => ['crl.zatca.gov.sa']]);
    }

    public function test_redirects_are_refused(): void
    {
        $this->assertFalse(SafeFetch::options('https://crl.zatca.gov.sa/x.crl', null)['allow_redirects']);
    }

    public function test_tls_is_verified(): void
    {
        $this->assertTrue(SafeFetch::options('https://crl.zatca.gov.sa/x.crl', null)['verify']);
    }

    public function test_limits_come_from_configuration(): void
    {
        config(['security.fetch.timeout_seconds' => 3]);

        $options = SafeFetch::options('https://crl.zatca.gov.sa/x.crl', null);

        $this->assertSame(3, $options['timeout']);
        $this->assertSame(3, $options['connect_timeout']);
    }

    /**
     * The fetch connects to the address SafeUrl checked, not to whatever the
     * name answers a moment later: a name can give the check a public
     * address and the connection a private one.
     */
    public function test_connection_is_pinned_to_checked_address(): void
    {
        config(['security.revocation_hosts' => []]);
        $this->resolves('93.184.216.34');
        $seen = [];

        Http::fake(function ($request, array $options) use (&$seen) {
            $seen = $options;

            return Http::response('list');
        });

        $this->assertSame('list', SafeFetch::get('https://crl.example.com/x.crl', 'security.revocation_hosts'));
        $this->assertSame(['crl.example.com:443:93.184.216.34'], $seen['curl'][CURLOPT_RESOLVE]);
        $this->assertFalse($seen['allow_redirects']);
    }

    public function test_plain_http_pins_port_80(): void
    {
        config(['security.revocation_hosts' => []]);
        $this->resolves('2606:4700:4700::1111');
        $seen = [];

        Http::fake(function ($request, array $options) use (&$seen) {
            $seen = $options;

            return Http::response('list');
        });

        $this->assertSame('list', SafeFetch::get('http://crl.example.com/x.crl', 'security.revocation_hosts', plainHttp: true));
        $this->assertSame(['crl.example.com:80:[2606:4700:4700::1111]'], $seen['curl'][CURLOPT_RESOLVE]);
    }

    /**
     * The limit holds while the body is read, not after: a server that
     * never stops sending is cut off just past it, not read to the end.
     */
    public function test_endless_body_is_cut_off(): void
    {
        config(['security.fetch.max_bytes' => 20_000]);
        $read = 0;
        $endless = new PumpStream(function () use (&$read): string {
            $read += 4096;

            return str_repeat('x', 4096);
        });

        Http::fake(['crl.zatca.gov.sa/*' => fn () => Create::promiseFor(new Response(200, [], $endless))]);

        $this->assertNull(SafeFetch::get('https://crl.zatca.gov.sa/x.crl', 'security.revocation_hosts'));
        $this->assertLessThan(40_000, $read);
    }

    public function test_declared_oversize_is_refused_unread(): void
    {
        config(['security.fetch.max_bytes' => 100]);

        Http::fake(['crl.zatca.gov.sa/*' => Http::response('small', 200, ['Content-Length' => '5000'])]);

        $this->assertNull(SafeFetch::get('https://crl.zatca.gov.sa/x.crl', 'security.revocation_hosts'));
    }

    public function test_body_within_limit_is_returned(): void
    {
        config(['security.fetch.max_bytes' => 100]);

        Http::fake(['crl.zatca.gov.sa/*' => Http::response(str_repeat('y', 100))]);

        $this->assertSame(str_repeat('y', 100), SafeFetch::get('https://crl.zatca.gov.sa/x.crl', 'security.revocation_hosts'));
    }

    public function test_redirect_answer_is_not_a_body(): void
    {
        Http::fake(['crl.zatca.gov.sa/*' => Http::response('moved', 302, ['Location' => 'https://169.254.169.254/'])]);

        $this->assertNull(SafeFetch::get('https://crl.zatca.gov.sa/x.crl', 'security.revocation_hosts'));
    }

    public function test_non_allowlisted_host_never_connects(): void
    {
        $this->assertNull(
            SafeFetch::get('https://evil.example.com/crl', 'security.revocation_hosts')
        );
    }

    public function test_private_address_never_connects(): void
    {
        config(['security.revocation_hosts' => []]);

        $this->assertNull(
            SafeFetch::get('https://169.254.169.254/latest/meta-data/', 'security.revocation_hosts')
        );
    }

    public function test_plain_http_never_connects(): void
    {
        config(['security.revocation_hosts' => []]);

        $this->assertNull(
            SafeFetch::get('http://crl.example.com/crl', 'security.revocation_hosts')
        );
    }

    public function test_malformed_url_never_connects(): void
    {
        $this->assertNull(SafeFetch::get('not a url', 'security.revocation_hosts'));
    }

    private function resolves(string $address): void
    {
        $this->app->instance(HostResolver::class, new class($address) extends HostResolver
        {
            public function __construct(private readonly string $address) {}

            public function addresses(string $host): array
            {
                return [$this->address];
            }
        });
    }
}
