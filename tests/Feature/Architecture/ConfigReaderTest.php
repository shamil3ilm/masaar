<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Every knob the platform documents has to reach something.
 *
 * config/fatoora.php carried a 'circuit_breaker' block — threshold, timeout,
 * sample size, an enabled flag — that nothing read. The breaker read
 * 'cluster_circuit_breaker' instead, whose environment variables were in no
 * example file. So the documented settings did nothing and the settings that
 * worked were undiscoverable: an operator raising the failure threshold during
 * a ZATCA incident would have changed nothing and had no way to tell.
 *
 * Config that lies is worse than config that is absent, because it is trusted.
 */
class ConfigReaderTest extends TestCase
{
    private const ROOT = __DIR__.'/../../..';

    /**
     * Config files this application owns and reads itself.
     *
     * The rest — app, database, queue, session and friends — are read by the
     * framework, which this test cannot see into.
     */
    private const OWNED = [
        'fatoora',
        'fta',
        'licensing',
        'metrics',
        'platform-license',
        'security',
    ];

    /**
     * Settings declared ahead of the code that will read them, and why.
     *
     * An entry is a statement that the block is deliberately waiting on work
     * in progress. If the reason is "it was easier", wire it up or delete it.
     */
    private const PENDING = [
        // The UAE mandate is 2027-01-01 and the FTA engine is part-built. The
        // client authenticates and submits with an API key today; these
        // describe the Peppol PINT AE path that is not finished. README marks
        // the jurisdiction as in development.
        'fta.peppol' => 'Peppol PINT AE identifiers, engine in development',
        'fta.connect_timeout' => 'UAE FTA transport, engine in development',
    ];

    public function test_every_config_block_has_a_reader(): void
    {
        $source = $this->source();
        $orphans = [];

        foreach (self::OWNED as $file) {
            $config = (array) config($file);

            foreach (array_keys($config) as $key) {
                if (array_key_exists("{$file}.{$key}", self::PENDING)) {
                    continue;
                }

                if (! str_contains($source, "{$file}.{$key}")) {
                    $orphans[] = "{$file}.{$key}";
                }
            }
        }

        $this->assertSame([], $orphans, sprintf(
            "These config keys are read by nothing. Wire them up or delete them:\n  %s",
            implode("\n  ", $orphans)
        ));
    }

    /**
     * An allowlist outlives the code it describes.
     *
     * Once a pending setting is wired up, its entry stops being a note about
     * unfinished work and becomes a hole in the check.
     */
    public function test_pending_list_has_no_stale_entries(): void
    {
        $source = $this->source();
        $stale = [];

        foreach (array_keys(self::PENDING) as $setting) {
            [$file, $key] = explode('.', $setting, 2);

            if (! array_key_exists($key, (array) config($file))) {
                $stale[] = "{$setting} no longer exists";

                continue;
            }

            if (str_contains($source, $setting)) {
                $stale[] = "{$setting} is read now — remove it from PENDING";
            }
        }

        $this->assertSame([], $stale, implode("\n", $stale));
    }

    /**
     * Application source, as one string to search.
     */
    private function source(): string
    {
        $source = '';

        foreach (['app', 'routes', 'database'] as $directory) {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(self::ROOT.'/'.$directory)
            );

            foreach ($files as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $source .= file_get_contents($file->getPathname());
                }
            }
        }

        return $source;
    }
}
