<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\SafeFetch;
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
 * address never reaches the socket. The transport policy is asserted as
 * configuration rather than by standing up a server and issuing a real
 * redirect; whether PHP honours follow_location is PHP's contract, not this
 * codebase's.
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
        $http = SafeFetch::transportPolicy()['http'];

        $this->assertSame(0, $http['follow_location']);
        $this->assertSame(0, $http['max_redirects']);
    }

    public function test_tls_is_verified(): void
    {
        $ssl = SafeFetch::transportPolicy()['ssl'];

        $this->assertTrue($ssl['verify_peer']);
        $this->assertTrue($ssl['verify_peer_name']);
    }

    /**
     * A 3xx or 4xx must arrive as a status this code inspects, not as a
     * warning with an empty body that reads like a successful empty fetch.
     */
    public function test_error_responses_are_readable(): void
    {
        $this->assertTrue(SafeFetch::transportPolicy()['http']['ignore_errors']);
    }

    public function test_limits_come_from_configuration(): void
    {
        config(['security.fetch.timeout_seconds' => 3]);

        $this->assertSame(3, SafeFetch::transportPolicy()['http']['timeout']);
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
}
