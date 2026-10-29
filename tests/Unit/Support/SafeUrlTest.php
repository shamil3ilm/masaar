<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\HostResolver;
use App\Support\SafeUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Certificate revocation endpoints are read from extensions inside the
 * certificate being validated, so whoever supplies the certificate chooses
 * the address. Unchecked, that turns the server into a fetcher for addresses
 * the caller cannot reach itself — cloud metadata, internal admin panels,
 * databases on the private network.
 */
class SafeUrlTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // No allowlist, so the address-based fallback is under test.
        config(['security.revocation_hosts' => []]);
    }

    #[DataProvider('blockedUrlProvider')]
    public function test_dangerous_targets_are_refused(string $url): void
    {
        $this->assertNotNull(SafeUrl::reject($url, 'security.revocation_hosts'));
        $this->assertFalse(SafeUrl::isFetchable($url, 'security.revocation_hosts'));
    }

    public static function blockedUrlProvider(): array
    {
        return [
            'cloud metadata' => ['https://169.254.169.254/latest/meta-data/'],
            'loopback' => ['https://127.0.0.1/crl'],
            'loopback by name' => ['https://localhost/crl'],
            'rfc1918 ten' => ['https://10.0.0.5/crl'],
            'rfc1918 192' => ['https://192.168.1.1/crl'],
            'rfc1918 172' => ['https://172.16.0.1/crl'],
            'carrier grade nat' => ['https://100.64.0.1/crl'],
            'this host' => ['https://0.0.0.0/crl'],
            'plain http' => ['http://crl.example.com/crl'],
            'file scheme' => ['file:///etc/passwd'],
            'gopher scheme' => ['gopher://127.0.0.1:70/'],
            'ipv6 loopback' => ['https://[::1]/crl'],
            'ipv6 unspecified' => ['https://[::]/crl'],
            'ipv6 link-local' => ['https://[fe80::1]/crl'],
            'ipv6 unique-local' => ['https://[fc00::1]/crl'],
            'ipv6 cloud metadata' => ['https://[fd00:ec2::254]/crl'],
            'ipv6 site-local' => ['https://[fec0::1]/crl'],
            'ipv6 multicast' => ['https://[ff02::1]/crl'],
            'ipv6 documentation' => ['https://[2001:db8::1]/crl'],
            'ipv4-mapped loopback' => ['https://[::ffff:127.0.0.1]/crl'],
            'ipv4-mapped private' => ['https://[::ffff:10.0.0.1]/crl'],
            'ipv4-mapped metadata hex' => ['https://[::ffff:a9fe:a9fe]/crl'],
            'nat64 metadata' => ['https://[64:ff9b::a9fe:a9fe]/crl'],
            '6to4 loopback' => ['https://[2002:7f00:1::1]/crl'],
            'teredo' => ['https://[2001:0:4136:e378::1]/crl'],
        ];
    }

    /**
     * A DNS failure and a private address are both refusals, but an operator
     * has to be able to tell them apart.
     */
    public function test_unresolvable_host_refused(): void
    {
        $reason = SafeUrl::reject('https://nx.invalid/crl', 'security.revocation_hosts');

        $this->assertNotNull($reason);
        $this->assertStringContainsString('could not be resolved', $reason);
    }

    public function test_private_address_reason_names_the_address(): void
    {
        $reason = SafeUrl::reject('https://10.0.0.5/crl', 'security.revocation_hosts');

        $this->assertStringContainsString('non-public', $reason);
        $this->assertStringContainsString('10.0.0.5', $reason);
    }

    /**
     * With an allowlist configured it is the only gate: the platform validates
     * certificates from one issuer chain, so an unvetted host has no business
     * being contacted whatever it resolves to.
     */
    public function test_allowlist_admits_only_listed_hosts(): void
    {
        config(['security.revocation_hosts' => ['crl.zatca.gov.sa']]);

        $this->assertTrue(SafeUrl::isFetchable('https://crl.zatca.gov.sa/x.crl', 'security.revocation_hosts'));
        $this->assertFalse(SafeUrl::isFetchable('https://evil.example.com/x.crl', 'security.revocation_hosts'));
    }

    public function test_allowlist_still_requires_https(): void
    {
        config(['security.revocation_hosts' => ['crl.zatca.gov.sa']]);

        $this->assertFalse(SafeUrl::isFetchable('http://crl.zatca.gov.sa/x.crl', 'security.revocation_hosts'));
    }

    /**
     * A leading dot allows that zone's subdomains and nothing else, so
     * "notzatca.gov.sa" cannot pass as a suffix match.
     */
    public function test_leading_dot_allows_subdomains_only(): void
    {
        config(['security.revocation_hosts' => ['.zatca.gov.sa']]);

        $this->assertTrue(SafeUrl::isFetchable('https://crl.zatca.gov.sa/x.crl', 'security.revocation_hosts'));
        $this->assertTrue(SafeUrl::isFetchable('https://zatca.gov.sa/x.crl', 'security.revocation_hosts'));
        $this->assertFalse(SafeUrl::isFetchable('https://evilzatca.gov.sa/x.crl', 'security.revocation_hosts'));
    }

    public function test_public_ipv6_literal_is_fetchable(): void
    {
        $this->assertTrue(SafeUrl::isFetchable('https://[2606:4700:4700::1111]/crl', 'security.revocation_hosts'));
    }

    /**
     * A name is checked on its AAAA records as well as its A records: the
     * fetch may connect over either, so a private IPv6 answer refuses it
     * however public the IPv4 one is.
     */
    public function test_private_aaaa_record_is_refused(): void
    {
        $this->resolves(['93.184.216.34', 'fd00::1']);

        $reason = SafeUrl::reject('https://crl.example.com/crl', 'security.revocation_hosts');

        $this->assertStringContainsString('fd00::1', (string) $reason);
    }

    public function test_public_aaaa_record_is_pinned(): void
    {
        $this->resolves(['2606:4700:4700::1111']);

        $this->assertSame([null, '2606:4700:4700::1111', false], SafeUrl::decide('https://crl.example.com/crl', 'security.revocation_hosts'));
    }

    public function test_malformed_url_is_refused(): void
    {
        $this->assertFalse(SafeUrl::isFetchable('not a url', 'security.revocation_hosts'));
    }

    /**
     * @param  list<string>  $addresses
     */
    private function resolves(array $addresses): void
    {
        $this->app->instance(HostResolver::class, new class($addresses) extends HostResolver
        {
            /** @param  list<string>  $addresses */
            public function __construct(private readonly array $addresses) {}

            public function addresses(string $host): array
            {
                return $this->addresses;
            }
        });
    }
}
