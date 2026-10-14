<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Who sent a request, when it arrives through the proxy.
 *
 * Behind Traefik every request reaches the app from Traefik's address. With
 * no proxy trusted, that is the address the app sees for everyone: every
 * client shares one rate-limit bucket, so one busy integrator throttles the
 * rest, and every audit row and failed-login record names the proxy.
 *
 * Trust is opt-in, by address. Trusting everything would let any client that
 * reached the app directly name its own address in X-Forwarded-For.
 */
class TrustedProxyTest extends TestCase
{
    private const PROXY = '172.18.0.2';

    private const CLIENT = '203.0.113.9';

    /**
     * The framework reads trustedproxy.proxies, and nothing set it: there was
     * no config file, so a deployment had no way to name its proxy at all.
     */
    public function test_the_deployment_names_its_proxy(): void
    {
        putenv('TRUSTED_PROXIES=172.16.0.0/12');

        try {
            $config = require base_path('config/trustedproxy.php');
        } finally {
            putenv('TRUSTED_PROXIES');
        }

        $this->assertSame('172.16.0.0/12', $config['proxies']);
    }

    public function test_a_trusted_proxy_names_the_client(): void
    {
        config(['trustedproxy.proxies' => '172.16.0.0/12']);

        $this->assertSame(self::CLIENT, $this->ipSeenFrom(self::PROXY));
    }

    /**
     * And a sender that is not the proxy cannot name an address for itself.
     */
    public function test_an_untrusted_sender_is_ignored(): void
    {
        config(['trustedproxy.proxies' => '172.16.0.0/12']);

        $this->assertSame('198.51.100.7', $this->ipSeenFrom('198.51.100.7'));
    }

    /**
     * Nothing is trusted unless a deployment says so.
     */
    public function test_nothing_is_trusted_by_default(): void
    {
        $this->assertNull(config('trustedproxy.proxies'));

        $this->assertSame(self::PROXY, $this->ipSeenFrom(self::PROXY));
    }

    private function ipSeenFrom(string $sender): string
    {
        $request = Request::create('/', 'GET', server: [
            'REMOTE_ADDR' => $sender,
            'HTTP_X_FORWARDED_FOR' => self::CLIENT,
        ]);

        return (string) app(TrustProxies::class)->handle($request, fn (Request $r): string => (string) $r->ip());
    }
}
