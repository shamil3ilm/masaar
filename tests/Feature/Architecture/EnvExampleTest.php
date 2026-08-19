<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Every setting this application reads must be named in .env.example.
 *
 * A knob read from the environment and documented nowhere is undiscoverable:
 * the deployment silently takes the default and the operator has no way to know
 * a choice was made. The whole UAE FTA integration was in that state — the
 * endpoint, the API key, the client id and secret were all read from the
 * environment and named in no file, so a deployment submitted to the sandbox
 * with no credential and failed at the authority rather than at startup.
 *
 * A commented-out entry counts. It still tells an operator the setting exists,
 * and is the right form for a secret or for a knob whose default should not be
 * disturbed.
 *
 * Only this application's own configuration is checked. The framework's files
 * carry hundreds of knobs that ship undocumented in every Laravel application,
 * and listing them would bury the settings that are actually ours.
 */
class EnvExampleTest extends TestCase
{
    private const ROOT = __DIR__.'/../../..';

    /**
     * Configuration this application owns, as opposed to the framework's.
     */
    private const OWN_CONFIG = [
        'cors',
        'fatoora',
        'fta',
        'jwt',
        'licensing',
        'metrics',
        'platform-license',
        'security',
    ];

    public function test_every_setting_is_documented(): void
    {
        $declared = $this->declaredKeys();
        $undocumented = [];

        foreach (self::OWN_CONFIG as $name) {
            $path = self::ROOT."/config/{$name}.php";

            if (! file_exists($path)) {
                continue;
            }

            foreach ($this->keysReadBy($path) as $key) {
                if (! isset($declared[$key])) {
                    $undocumented[] = "{$name}.php reads {$key}";
                }
            }
        }

        $this->assertSame([], $undocumented, sprintf(
            'These settings are read from the environment but named nowhere in '
            ."\n.env.example, so nobody deploying this can know they exist:\n  %s",
            implode("\n  ", $undocumented)
        ));
    }

    /**
     * The reverse: .env.example must not advertise a setting nothing reads.
     *
     * UAE_FTA_WEBHOOK_SECRET was configured for a callback endpoint that does
     * not exist — FTA submission status is polled, not pushed. Documenting it
     * would have told an operator that inbound callbacks are verified when no
     * code reads the value at all.
     */
    public function test_no_setting_is_advertised_but_unread(): void
    {
        $read = [];

        // The framework's own defaults are searched as well as ours. A config
        // file that has not been published still reads its settings from the
        // environment — BROADCAST_CONNECTION and BCRYPT_ROUNDS are live here
        // even though neither broadcasting.php nor hashing.php exists locally.
        $sources = array_merge(
            glob(self::ROOT.'/config/*.php') ?: [],
            glob(self::ROOT.'/vendor/laravel/framework/config/*.php') ?: []
        );

        foreach ($sources as $path) {
            foreach ($this->keysReadBy($path) as $key) {
                $read[$key] = true;
            }
        }

        $stale = [];

        foreach (array_keys($this->declaredKeys()) as $key) {
            if (isset($read[$key]) || $this->readOutsideConfig($key)) {
                continue;
            }

            $stale[] = $key;
        }

        $this->assertSame([], $stale, sprintf(
            '.env.example names settings that no config file reads. Either wire '
            ."them up or remove them:\n  %s",
            implode("\n  ", $stale)
        ));
    }

    /**
     * Settings consumed by something other than a config file.
     */
    private function readOutsideConfig(string $key): bool
    {
        return match (true) {
            // Read by the bootstrapper before any config file loads.
            in_array($key, ['APP_KEY', 'APP_ENV', 'APP_DEBUG'], true) => true,
            // Read by the PHP binary's built-in server, not by the application.
            $key === 'PHP_CLI_SERVER_WORKERS' => true,
            // Substituted into the front-end bundle at build time.
            str_starts_with($key, 'VITE_') => true,
            default => false,
        };
    }

    /**
     * @return array<string, true>
     */
    private function declaredKeys(): array
    {
        $declared = [];

        foreach (file(self::ROOT.'/.env.example') ?: [] as $line) {
            if (preg_match('/^#?\s*([A-Z][A-Z0-9_]*)=/', trim($line), $match) === 1) {
                $declared[$match[1]] = true;
            }
        }

        return $declared;
    }

    /**
     * @return list<string>
     */
    private function keysReadBy(string $path): array
    {
        preg_match_all(
            '/env\(\s*[\'"]([A-Z][A-Z0-9_]*)[\'"]/',
            (string) file_get_contents($path),
            $matches
        );

        return array_values(array_unique($matches[1]));
    }
}
