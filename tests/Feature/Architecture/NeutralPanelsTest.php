<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Shared screens name no tax authority or network.
 *
 * Every panel - the portal, the admin console, the emails - serves every
 * country the engine has. A shared view that says ZATCA, Fatoora, Peppol or
 * FTA is wrong for every other country's organizations, and is where a
 * country's screen lives outside its module. Shared screens use the neutral
 * words (tax authority, filing, certificate, connection) and take a name
 * that matters from the organization's jurisdiction; a country's own screens
 * live in resources/views/countries/<country>/, a network's in
 * resources/views/networks/<network>/, and reach the panels through the
 * module (Presentation: navigation, notice).
 *
 * Checked, each a ratchet of file => occurrences that may only shrink:
 * shared views, layouts and emails, word for word, comments included; the
 * translations their strings and shared PHP's resolve to; and shared PHP's
 * prose - a string literal naming one of them as a word.
 *
 * Allowed: the modules and their views, the public website (home/, legal/,
 * its layout and the home page, which may name them by the owner's choice),
 * and docs. The console's own words are English only.
 *
 * Counted when it was written: 52 shared view files naming them 169 times,
 * 31 files whose strings translated to them 109 times, and 16 shared PHP
 * files saying them 33 times. All three lists are empty now and stay so.
 */
class NeutralPanelsTest extends TestCase
{
    private const ROOT = __DIR__.'/../../..';

    /** The words, in a view or a translation. */
    private const WORDS = '/zatca|fatoora|peppol|\bFTA\b|هيئة الزكاة|الهيئة الاتحادية للضرائب/iu';

    /** The words as prose in PHP: not a config key, an identifier or a code. */
    private const PROSE = '/\b(?:ZATCA|Fatoora|Peppol|FTA)\b/';

    /** Views that are not a shared panel's. */
    private const VIEWS_ALLOWED = [
        'resources/views/countries/',
        'resources/views/networks/',
        // The public website.
        'resources/views/home/',
        'resources/views/legal/',
        'resources/views/layouts/public.blade.php',
        'resources/views/welcome.blade.php',
    ];

    /** PHP that is a module's, not shared. */
    private const PHP_ALLOWED = [
        'app/Domains/Compliance/Countries/',
        'app/Domains/Compliance/Networks/',
    ];

    /**
     * Shared views naming an authority or a network, and how often.
     *
     * @var array<string, int>
     */
    private const VIEWS_DECLARED = [];

    /**
     * Strings of shared views and shared PHP whose translation names one.
     *
     * @var array<string, int>
     */
    private const TRANSLATIONS_DECLARED = [];

    /**
     * Shared PHP whose prose names one, and how often.
     *
     * @var array<string, int>
     */
    private const PHP_DECLARED = [];

    public function test_shared_views_name_no_authority(): void
    {
        $found = [];

        foreach ($this->sharedViews() as $relative => $source) {
            $count = preg_match_all(self::WORDS, $source);

            if ($count > 0) {
                $found[$relative] = $count;
            }
        }

        $this->assertSame(self::VIEWS_DECLARED, $found, "Shared views naming an authority or a network changed. Found:\n".var_export($found, true));
    }

    public function test_their_translations_name_none(): void
    {
        $arabic = json_decode((string) file_get_contents(self::ROOT.'/lang/ar.json'), true, flags: JSON_THROW_ON_ERROR);
        $found = [];

        foreach ([...$this->sharedViews(), ...$this->sharedPhp()] as $relative => $source) {
            $count = 0;

            foreach (array_unique($this->keys($source)) as $key) {
                $count += preg_match_all(self::WORDS, $key.' '.($arabic[$key] ?? ''));
            }

            if ($count > 0) {
                $found[$relative] = $count;
            }
        }

        ksort($found);

        $this->assertSame(self::TRANSLATIONS_DECLARED, $found, "Translated strings naming an authority or a network changed. Found:\n".var_export($found, true));
    }

    public function test_shared_php_says_none(): void
    {
        $found = [];

        foreach ($this->sharedPhp() as $relative => $source) {
            $count = 0;

            foreach (token_get_all($source) as $token) {
                if (is_array($token) && in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                    $count += preg_match_all(self::PROSE, $token[1]);
                }
            }

            if ($count > 0) {
                $found[$relative] = $count;
            }
        }

        $this->assertSame(self::PHP_DECLARED, $found, "Shared PHP naming an authority or a network in its words changed. Found:\n".var_export($found, true));
    }

    /**
     * @return array<string, string> relative path => source
     */
    private function sharedViews(): array
    {
        return array_filter(
            $this->files('resources/views'),
            fn (string $relative): bool => ! $this->startsWithAny($relative, self::VIEWS_ALLOWED),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * @return array<string, string> relative path => source
     */
    private function sharedPhp(): array
    {
        return array_filter(
            $this->files('app'),
            fn (string $relative): bool => ! $this->startsWithAny($relative, self::PHP_ALLOWED),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * The literal first arguments of __() calls, as TranslationTest reads them.
     *
     * @return list<string>
     */
    private function keys(string $source): array
    {
        preg_match_all("/(?<![\\w>])__\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/", $source, $single);
        preg_match_all('/(?<![\\w>])__\\(\\s*"((?:[^"\\\\$]|\\\\.)*)"/', $source, $double);

        return [
            ...array_map(fn (string $key): string => stripcslashes(str_replace("\\'", "'", $key)), $single[1]),
            ...array_map(fn (string $key): string => stripcslashes($key), $double[1]),
        ];
    }

    /**
     * @param  list<string>  $prefixes
     */
    private function startsWithAny(string $relative, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if (str_starts_with($relative, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, string> relative path => source, sorted
     */
    private function files(string $folder): array
    {
        $root = str_replace('\\', '/', (string) realpath(self::ROOT)).'/';
        $files = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.$folder, RecursiveDirectoryIterator::SKIP_DOTS));

        foreach ($it as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $path = str_replace('\\', '/', (string) $file->getPathname());
                $files[substr($path, strlen($root))] = (string) file_get_contents($path);
            }
        }

        ksort($files);

        return $files;
    }
}
