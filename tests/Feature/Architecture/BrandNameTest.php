<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * The product's name is written once, in config/brand.php.
 *
 * The name may change while the logo stays. Everything else reads it from
 * the configuration (App\Support\Brand, :app in a translated line), so a
 * rename is that file and the environment - never a search through code,
 * commands, headers, metrics, certificate requests, translations and tests.
 *
 * Outside the scan: config/brand.php itself, docs/ and README-type files
 * (their text is renamed by a search-and-replace at the time), the logo's
 * SVG assets, git's own metadata, and what the build or the machine writes
 * (vendor, node_modules, public/build, storage, bootstrap/cache, .env).
 *
 * The name is held here as SHA-256, as PublicDocsTest holds partner names,
 * so this file does not spell what it forbids.
 */
class BrandNameTest extends TestCase
{
    private const ROOT = __DIR__.'/../../..';

    /** SHA-256 of the name in English, lowercased, and in Arabic. */
    private const NAMES = [
        '5390b0c25867e3d1cd6c189c40389840329fcdad0f4e080d75b36ed0618d7a29',
        'a01e01e676b1878eb8228a4e3402e463965e25c5d583944058ffb37c34179797',
    ];

    /** The project's own folders, as the repository tracks them. */
    private const FOLDERS = [
        '.github', 'app', 'bootstrap', 'cloudflare-worker', 'config', 'database', 'docker',
        'lang', 'public', 'resources', 'routes', 'scripts', 'tests',
    ];

    /** The project's own files at the root that are not README-type. */
    private const ROOT_FILES = [
        '.dockerignore', '.editorconfig', '.env.example', '.gitattributes', '.gitignore', '.mcp.json',
        'Dockerfile', 'artisan', 'boost.json', 'composer.json', 'docker-compose.override.yml',
        'docker-compose.prod.yml', 'docker-compose.yml', 'package-lock.json', 'package.json',
        'phpunit.xml', 'vite.config.js',
    ];

    /** Written by the build or the machine, not by us. */
    private const GENERATED = ['bootstrap/cache/', 'public/build/', 'public/hot', 'public/storage'];

    /**
     * Written from config/brand.php by a command, for a moment when nothing
     * can read it: the page nginx serves while PHP is down
     * (`php artisan platform:static-errors`). StaticErrorPageTest fails when
     * it is not what the brand would write, as SchemaDriftTest does for
     * docs/schema.sql.
     */
    private const FROM_THE_BRAND = ['public/50x.html'];

    /**
     * Lines that may keep the name, by file: the env templates' APP_NAME,
     * which is the brand's default and feeds config/brand.php.
     *
     * @var array<string, list<string>>
     */
    private const ALLOWED_LINES = [
        '.env.example' => ['APP_NAME='],
        'docker/.env.template' => ['APP_NAME='],
    ];

    /**
     * Where the Arabic word is the ordinary noun - a path, a route - and not
     * the product: the phrase it stands in, by file.
     *
     * @var array<string, list<string>>
     */
    private const ORDINARY_ARABIC = [
        // "Breadcrumb": a navigation path.
        'lang/ar.json' => ['مسار التنقل'],
        // This list, naming that phrase.
        'tests/Feature/Architecture/BrandNameTest.php' => ['مسار التنقل'],
    ];

    public function test_the_name_is_written_once(): void
    {
        $found = [];

        foreach ($this->files() as $relative => $path) {
            foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $number => $line) {
                if ($this->namesTheProduct($relative, $line)) {
                    $found[] = $relative.':'.($number + 1);
                }
            }
        }

        $this->assertSame([], $found, "The product's name is written outside config/brand.php. Read it from Brand, or say :app in a translated line:\n  ".implode("\n  ", $found));
    }

    /**
     * The hashes are what the scan compares against, so a wrong one would
     * pass everything: each must be a name config/brand.php gives as its
     * default.
     */
    public function test_the_hashes_are_the_defaults(): void
    {
        $brand = (string) file_get_contents(self::ROOT.'/config/brand.php');
        preg_match_all("/env\\('(?:APP_NAME|BRAND_NAME_AR)', '([^']+)'\\)/u", $brand, $defaults);

        $this->assertCount(2, $defaults[1], 'config/brand.php no longer reads both names with a default.');
        $this->assertSame(self::NAMES, array_map(fn (string $name): string => hash('sha256', mb_strtolower($name)), $defaults[1]));
    }

    private function namesTheProduct(string $relative, string $line): bool
    {
        foreach (self::ALLOWED_LINES[$relative] ?? [] as $start) {
            if (str_starts_with($line, $start)) {
                return false;
            }
        }

        foreach (self::ORDINARY_ARABIC[$relative] ?? [] as $phrase) {
            $line = str_replace($phrase, '', $line);
        }

        $lower = mb_strtolower($line);

        // The English name inside any word (a slug, a metric, a host), and
        // the Arabic one as a word of its own: "مسارها" is "its path".
        preg_match_all('/(?<!\p{L})\p{Arabic}{4}(?!\p{L})/u', $lower, $arabic);

        foreach ($this->windows($lower) as $candidate) {
            if (in_array(hash('sha256', $candidate), self::NAMES, true)) {
                return true;
            }
        }

        foreach ($arabic[0] as $word) {
            if (in_array(hash('sha256', $word), self::NAMES, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every six-letter run of a line's Latin letters, so the name is found
     * inside a longer identifier as well as on its own.
     *
     * @return list<string>
     */
    private function windows(string $line): array
    {
        $windows = [];

        foreach (preg_split('/[^a-z]+/', $line, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $run) {
            for ($at = 0; $at + 6 <= strlen($run); $at++) {
                $windows[] = substr($run, $at, 6);
            }
        }

        return $windows;
    }

    /**
     * @return array<string, string> relative path => absolute path
     */
    private function files(): array
    {
        $root = str_replace('\\', '/', (string) realpath(self::ROOT)).'/';
        $files = [];

        foreach (self::ROOT_FILES as $file) {
            if (is_file($root.$file)) {
                $files[$file] = $root.$file;
            }
        }

        foreach (self::FOLDERS as $folder) {
            if (! is_dir($root.$folder)) {
                continue;
            }

            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.$folder, RecursiveDirectoryIterator::SKIP_DOTS));

            foreach ($it as $file) {
                $path = str_replace('\\', '/', (string) $file->getPathname());
                $relative = substr($path, strlen($root));

                if ($file->isFile() && ! $this->exempt($relative)) {
                    $files[$relative] = $path;
                }
            }
        }

        ksort($files);

        return $files;
    }

    private function exempt(string $relative): bool
    {
        foreach (self::GENERATED as $prefix) {
            if (str_starts_with($relative, $prefix)) {
                return true;
            }
        }

        return $relative === 'config/brand.php'
            || in_array($relative, self::FROM_THE_BRAND, true)
            // README-type files, wherever they are.
            || str_ends_with(strtolower($relative), '.md')
            // The logo's artwork.
            || str_ends_with(strtolower($relative), '.svg');
    }
}
