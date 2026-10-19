<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Everything the portal says in English it can also say in Arabic.
 *
 * A string wrapped in __() with no Arabic beside it shows in English on an
 * Arabic page - a page that reads half in one language and half in the
 * other, with no error anywhere. This reads every string the customer-facing
 * screens and their controllers wrap, and fails for one lang/ar.json does not
 * have.
 *
 * The admin console is not in scope: it is the operator's, in English.
 */
class TranslationTest extends TestCase
{
    private const ROOT = __DIR__.'/../../..';

    /** Directories and files whose wrapped strings must have Arabic. */
    private const SCOPE = [
        'resources/views/portal',
        'resources/views/countries',
        'resources/views/networks',
        'resources/views/auth',
        'resources/views/account',
        'resources/views/components',
        'resources/views/branding',
        'resources/views/layouts/portal.blade.php',
        'resources/views/layouts/guest.blade.php',
        'resources/views/layouts/public.blade.php',
        'resources/views/welcome.blade.php',
        'resources/views/home',
        'resources/views/errors',
        'resources/views/legal',
        'resources/views/emails',
        'app/Domains',
        'app/Http',
    ];

    public function test_every_wrapped_string_has_arabic(): void
    {
        $arabic = json_decode((string) file_get_contents(self::ROOT.'/lang/ar.json'), true, flags: JSON_THROW_ON_ERROR);
        $missing = [];

        foreach ($this->files() as $relative => $source) {
            foreach ($this->keys($source) as $key) {
                if (! array_key_exists($key, $arabic) || trim((string) $arabic[$key]) === '') {
                    $missing[] = $relative.': '.$key;
                }
            }
        }

        $this->assertSame([], array_values(array_unique($missing)),
            'These strings are wrapped in __() and have no Arabic in lang/ar.json.');
    }

    /**
     * Placeholders survive translation: an Arabic line that drops :name
     * prints the literal word where the value should be.
     */
    public function test_placeholders_survive_translation(): void
    {
        $arabic = json_decode((string) file_get_contents(self::ROOT.'/lang/ar.json'), true, flags: JSON_THROW_ON_ERROR);
        $broken = [];

        foreach ($arabic as $english => $translation) {
            preg_match_all('/:[a-z_]+/', (string) $english, $wanted);
            preg_match_all('/:[a-z_]+/', (string) $translation, $given);

            $lost = array_diff($wanted[0], $given[0]);

            if ($lost !== []) {
                $broken[] = $english.' (loses '.implode(', ', $lost).')';
            }
        }

        $this->assertSame([], $broken);
    }

    /**
     * A key written twice is decoded as its last copy, silently: an edit to
     * the first changes nothing on the page. Read as text, since json_decode
     * keeps no record of what it overwrote.
     */
    public function test_no_key_is_written_twice(): void
    {
        $twice = [];

        foreach (glob(self::ROOT.'/lang/*.json') ?: [] as $file) {
            $json = (string) file_get_contents($file);

            // Every JSON string in order; one followed by a colon is a key.
            preg_match_all('/"(?:[^"\\\\]|\\\\.)*"/', $json, $strings, PREG_OFFSET_CAPTURE);
            $seen = [];

            foreach ($strings[0] as [$string, $offset]) {
                if (preg_match('/\G\s*:/', $json, $colon, 0, $offset + strlen($string)) !== 1) {
                    continue;
                }

                $key = (string) json_decode($string, flags: JSON_THROW_ON_ERROR);
                isset($seen[$key]) && $twice[] = basename($file).': '.$key;
                $seen[$key] = true;
            }
        }

        $this->assertSame([], $twice, 'These keys are written twice; only the last copy is ever read.');
    }

    /**
     * @return array<string, string>
     */
    private function files(): array
    {
        $files = [];

        foreach (self::SCOPE as $entry) {
            $path = self::ROOT.'/'.$entry;

            if (is_file($path)) {
                $files[$entry] = (string) file_get_contents($path);

                continue;
            }

            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));

            foreach ($iterator as $file) {
                if (str_ends_with($file->getFilename(), '.php')) {
                    $relative = ltrim(str_replace([str_replace('\\', '/', realpath(self::ROOT)), '\\'], ['', '/'], str_replace('\\', '/', $file->getRealPath())), '/');
                    $files[$relative] = (string) file_get_contents($file->getPathname());
                }
            }
        }

        return $files;
    }

    /**
     * The literal first arguments of __() calls.
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
}
