<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * What the compose files put on the network, and what Redis may throw away.
 *
 * Docker publishes a port by writing its own firewall rules, ahead of the
 * host's, so a mapping with no address is reachable from anywhere the host is
 * - whatever ufw says. MySQL and Redis were published that way, Redis with a
 * password every copy of this repository knows, and the app's own port was
 * too: behind Traefik that is a second way in that skips TLS, and once
 * proxies are trusted it is one where a client can name its own address.
 *
 * Bound to loopback, each still answers on the host for development and
 * administration. In production Traefik reaches the app over the compose
 * network, which a published port has nothing to do with.
 */
class ComposeExposureTest extends TestCase
{
    private const BASE = __DIR__.'/../../../docker-compose.yml';

    private const PROD = __DIR__.'/../../../docker-compose.prod.yml';

    public function test_no_port_is_public(): void
    {
        $public = [];

        foreach ($this->services(self::BASE) as $name => $service) {
            foreach ((array) ($service['ports'] ?? []) as $port) {
                if (! str_starts_with((string) $port, '127.0.0.1:')) {
                    $public[] = $name.': '.$port;
                }
            }
        }

        $this->assertSame([], $public,
            'These are published on every interface. Prefix the mapping with 127.0.0.1: - '
            .'Docker\'s rules sit ahead of the host firewall, so nothing else will stop them.');
    }

    /**
     * The override may publish exactly one thing: the proxy that terminates TLS.
     */
    public function test_only_the_proxy_is_public(): void
    {
        $publishing = [];

        foreach ($this->services(self::PROD) as $name => $service) {
            if (! empty($service['ports'])) {
                $publishing[] = $name;
            }
        }

        $this->assertSame(['traefik'], $publishing);
    }

    /**
     * Redis has no password unless one is given, and then both sides use it.
     *
     * The server fell back to masaar_redis and the app to null, so a stack
     * started without one ran Redis under a published default - and the app
     * could not authenticate to it anyway. Required on both sides, compose
     * refuses to start without it and says why.
     */
    public function test_redis_has_no_default_password(): void
    {
        foreach ([self::BASE, self::PROD] as $file) {
            $services = $this->services($file);

            if (! isset($services['redis']['command'])) {
                continue;
            }

            $this->assertStringContainsString('${REDIS_PASSWORD:?', (string) $services['redis']['command'],
                basename($file).' starts Redis without requiring a password.');
        }

        $app = (string) ($this->services(self::BASE)['app']['environment']['REDIS_PASSWORD'] ?? '');

        $this->assertStringStartsWith('${REDIS_PASSWORD:?', $app,
            'The app container must take the same required password the server does.');
    }

    /**
     * Redis holds the queue, so it may refuse a write but never drop a key.
     *
     * allkeys-lru evicts whatever was used least recently once memory is
     * full, and a filing job waiting its turn is exactly that. The job is
     * gone, nothing fails, and the invoice is never reported. noeviction
     * turns the same pressure into an error at the write, where it is seen.
     */
    public function test_redis_never_evicts(): void
    {
        foreach ([self::BASE, self::PROD] as $file) {
            $command = (string) ($this->services($file)['redis']['command'] ?? '');

            if (! str_contains($command, 'maxmemory-policy')) {
                continue;
            }

            $this->assertStringContainsString('--maxmemory-policy noeviction', $command,
                basename($file).' lets Redis evict keys, and the queue lives there.');
        }
    }

    /** @return array<string, array<string, mixed>> */
    private function services(string $file): array
    {
        return (array) (Yaml::parseFile($file)['services'] ?? []);
    }
}
