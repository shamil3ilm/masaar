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

    private const DEV = __DIR__.'/../../../docker-compose.override.yml';

    public function test_no_port_is_public(): void
    {
        $public = [];

        foreach ([self::BASE, self::DEV] as $file) {
            foreach ($this->services($file) as $name => $service) {
                foreach ((array) ($service['ports'] ?? []) as $port) {
                    if (! str_starts_with((string) $port, '127.0.0.1:')) {
                        $public[] = basename($file).' '.$name.': '.$port;
                    }
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
     * Production runs more than one app container, so nothing may pin it to one.
     *
     * The override asked for two replicas while the base file named the
     * container masaar-app and bound it to a host port. Compose refuses both
     * for a second container, so the production stack could not start the
     * app at all.
     */
    public function test_the_app_can_be_replicated(): void
    {
        $replicas = (int) ($this->services(self::PROD)['app']['deploy']['replicas'] ?? 1);

        if ($replicas < 2) {
            $this->markTestSkipped('Production runs a single app container.');
        }

        $app = $this->services(self::BASE)['app'];

        $this->assertArrayNotHasKey('container_name', $app, 'A named container cannot be replicated.');
        $this->assertArrayNotHasKey('ports', $app, 'Two replicas cannot bind one host port.');
        $this->assertArrayNotHasKey('ports', $this->services(self::PROD)['app']);
    }

    /**
     * Redis never starts without a password, and both sides read one variable.
     *
     * The server fell back to masaar_redis and the app to null, so a stack
     * started without one ran Redis under a published default - and the app
     * could not authenticate to it anyway. Redis is optional now, so the
     * requirement lives in the container: compose checks ${VAR:?} across the
     * whole file and would demand a password for a service it is not running.
     */
    public function test_redis_has_no_default_password(): void
    {
        foreach ([self::BASE, self::PROD] as $file) {
            $command = $this->redisCommand($file);

            if ($command === '') {
                continue;
            }

            $this->assertStringContainsString('test -n "$$REDIS_PASSWORD" ||', $command,
                basename($file).' starts Redis without requiring a password.');
            $this->assertStringContainsString('--requirepass "$$REDIS_PASSWORD"', $command,
                basename($file).' starts Redis under a password other than REDIS_PASSWORD.');
        }

        $base = $this->services(self::BASE);

        $this->assertSame('${REDIS_PASSWORD:-}', $base['redis']['environment']['REDIS_PASSWORD'] ?? null,
            'The server must read REDIS_PASSWORD, with no default to fall back on.');
        $this->assertSame('${REDIS_PASSWORD:-}', $base['app']['environment']['REDIS_PASSWORD'] ?? null,
            'The app must read the same REDIS_PASSWORD the server does.');
    }

    /**
     * The stack runs without Redis: it is a profile, and the drivers default
     * to the database. A deployment with no Redis to give it must not have
     * the app waiting on one, or writing its cache to one that is not there.
     */
    public function test_redis_is_optional(): void
    {
        foreach ([self::BASE, self::PROD] as $file) {
            $this->assertSame(['redis'], $this->services($file)['redis']['profiles'] ?? null,
                basename($file).' runs Redis whether it is wanted or not.');
        }

        $app = $this->services(self::BASE)['app'];

        $this->assertFalse($app['depends_on']['redis']['required'] ?? true,
            'The app would refuse to start without a Redis that is optional.');

        foreach (['CACHE_STORE', 'SESSION_DRIVER', 'QUEUE_CONNECTION'] as $variable) {
            $this->assertSame('${'.$variable.':-database}', $app['environment'][$variable] ?? null,
                "$variable does not default to the database.");
        }
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
            $command = $this->redisCommand($file);

            if (! str_contains($command, 'maxmemory-policy')) {
                continue;
            }

            $this->assertStringContainsString('--maxmemory-policy noeviction', $command,
                basename($file).' lets Redis evict keys, and the queue lives there.');
        }
    }

    /**
     * The Redis command as one string, whether written as a string or a list.
     */
    private function redisCommand(string $file): string
    {
        $command = $this->services($file)['redis']['command'] ?? '';

        return is_array($command) ? implode(' ', $command) : (string) $command;
    }

    /** @return array<string, array<string, mixed>> */
    private function services(string $file): array
    {
        return (array) (Yaml::parseFile($file)['services'] ?? []);
    }
}
