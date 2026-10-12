<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Every setting the containers pass to the application is one it reads.
 *
 * `docker-compose.yml` and `docker/.env.template` set `CACHE_DRIVER=redis`,
 * and Laravel 11 reads `CACHE_STORE`. So a deployment started a Redis
 * container, protected it with a password, passed it to the app - and the app
 * used the database cache, because nothing ever read the key that said
 * otherwise. Nothing failed. Redis sat there unused and every cache write went
 * to MySQL.
 *
 * That is the shape of configuration drift worth a test: a setting that is
 * wrong in a way the application cannot complain about, because from its side
 * the value simply does not exist. `EnvExampleTest` holds the same line for
 * `.env.example`; the container templates had no such check and are the ones
 * that describe production.
 *
 * Infrastructure keys are allowed by name. Compose legitimately carries values
 * that are nothing to do with Laravel - which port to publish, what to call
 * the MySQL database, which image tag to pull - and a test that demanded the
 * application read those would be wrong rather than strict.
 */
class DockerEnvironmentTest extends TestCase
{
    private const ROOT = __DIR__.'/../../..';

    /**
     * The container templates that configure the application.
     */
    private const TEMPLATES = [
        'docker/.env.template',
        'docker-compose.yml',
        'docker-compose.prod.yml',
    ];

    /**
     * Settings consumed by something other than the Laravel application:
     * the compose file itself, the entrypoint, or another image.
     */
    private const INFRASTRUCTURE = [
        // Published ports and the hostname nginx answers on.
        'APP_DOMAIN', 'APP_PORT', 'DB_EXTERNAL_PORT', 'REDIS_EXTERNAL_PORT',
        // Read by docker/entrypoint.sh, not by config.
        'AUTO_MIGRATE',
        // Consumed by the mysql image to create its database and users.
        'MYSQL_DATABASE', 'MYSQL_PASSWORD', 'MYSQL_ROOT_PASSWORD', 'MYSQL_USER',
        'DB_ROOT_PASSWORD',
        // Which build to run, and who Let's Encrypt emails.
        'IMAGE_TAG', 'SSL_EMAIL',
    ];

    public function test_every_container_setting_is_read(): void
    {
        $read = $this->settingsTheApplicationReads();
        $unread = [];

        foreach (self::TEMPLATES as $template) {
            $path = self::ROOT.'/'.$template;

            if (! file_exists($path)) {
                continue;
            }

            foreach ($this->settingsDeclaredIn($path) as $key) {
                if (isset($read[$key]) || in_array($key, self::INFRASTRUCTURE, true)) {
                    continue;
                }

                $unread[] = $template.' sets '.$key;
            }
        }

        $this->assertSame([], $unread, implode("\n", $unread)."\n\n"
            .'These are passed to the application and no config file reads them, '
            .'so they have no effect and nothing will say so. Either the name is '
            .'wrong - CACHE_DRIVER was, where Laravel 11 reads CACHE_STORE - or '
            .'the setting belongs to the infrastructure, in which case name it in '
            .'INFRASTRUCTURE and say what consumes it.');
    }

    /**
     * @return array<string, true>
     */
    private function settingsTheApplicationReads(): array
    {
        $read = [];

        $sources = array_merge(
            glob(self::ROOT.'/config/*.php') ?: [],
            glob(self::ROOT.'/vendor/laravel/framework/config/*.php') ?: []
        );

        foreach ($sources as $path) {
            preg_match_all(
                '/env\(\s*[\'"]([A-Z][A-Z0-9_]*)[\'"]/',
                (string) file_get_contents($path),
                $matches
            );

            foreach ($matches[1] as $key) {
                $read[$key] = true;
            }
        }

        // Read by the bootstrapper before any config file loads.
        foreach (['APP_KEY', 'APP_ENV', 'APP_DEBUG'] as $key) {
            $read[$key] = true;
        }

        return $read;
    }

    /**
     * @return list<string>
     */
    private function settingsDeclaredIn(string $path): array
    {
        preg_match_all(
            '/^\s*([A-Z][A-Z0-9_]*)\s*[:=]/m',
            (string) file_get_contents($path),
            $matches
        );

        return array_values(array_unique($matches[1]));
    }
}
