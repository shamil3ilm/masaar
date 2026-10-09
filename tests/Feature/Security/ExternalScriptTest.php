<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * What the consoles execute from somewhere else.
 *
 * A script tag pointing at a third party is that third party's code running
 * with the session of whoever is looking at the page - and these pages are an
 * authenticated admin console and a taxpayer portal. Two things make that
 * worse than it needs to be, and both are present:
 *
 *   unpkg.com/alpinejs@3.x.x is a floating version. It resolves to whatever
 *   the registry serves for 3.x today, so the code can change without
 *   anything here changing, and a bad release reaches an operator's browser
 *   with no deployment.
 *
 *   Neither tag carries an integrity attribute, so a substituted file is
 *   executed rather than refused.
 *
 * cdn.tailwindcss.com is the Play CDN as well, which compiles stylesheets in
 * the browser and is documented as unsuitable for production. It is also why
 * SecurityHeaders sets no Content-Security-Policy: a policy allowing the
 * unsafe-eval and unsafe-inline it needs protects against little, and one
 * without them breaks every page.
 *
 * Fixing it means pinning a version and adding an integrity hash, or
 * vendoring the files and building the stylesheet. Both need the files
 * fetched, so neither was attempted here.
 *
 * What this test does is hold the line: these two, in these four files, and
 * no more. A third is a build failure rather than something discovered by a
 * scanner months later - which is how these two were found. Named
 * individually rather than counted, so swapping one for another is also
 * caught.
 */
class ExternalScriptTest extends TestCase
{
    private const VIEWS = __DIR__.'/../../../resources/views';

    /**
     * Known, outstanding, and not to be added to.
     */
    private const ALLOWED = [
        'https://cdn.tailwindcss.com',
        'https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js',
    ];

    public function test_no_new_third_party_script(): void
    {
        $found = [];

        foreach ($this->views() as $path) {
            preg_match_all(
                '/<script[^>]+src=["\'](https?:\/\/[^"\']+)["\']/i',
                (string) file_get_contents($path),
                $matches
            );

            foreach ($matches[1] as $src) {
                if (! in_array($src, self::ALLOWED, true)) {
                    $found[] = basename($path).' loads '.$src;
                }
            }
        }

        $this->assertSame([], $found, implode("\n", $found)."\n\n"
            .'A page here executes third-party code with the session of whoever '
            .'is looking at it. Vendor the file, or pin a version and add an '
            .'integrity attribute. If it genuinely has to be remote and '
            .'unpinned, add it to ALLOWED and say why.');
    }

    /**
     * And the two that are allowed are still there, so this does not quietly
     * become a test of nothing once they are fixed - at which point the
     * entries come out and the assertion above covers everything.
     */
    public function test_the_allowed_list_is_not_stale(): void
    {
        $all = '';

        foreach ($this->views() as $path) {
            $all .= (string) file_get_contents($path);
        }

        $gone = [];

        foreach (self::ALLOWED as $src) {
            if (! str_contains($all, $src)) {
                $gone[] = $src;
            }
        }

        $this->assertSame([], $gone, 'No longer loaded anywhere, so remove from ALLOWED: '
            .implode(', ', $gone));
    }

    /**
     * @return list<string>
     */
    private function views(): array
    {
        $out = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::VIEWS));

        foreach ($it as $file) {
            if (! $file->isDir() && str_ends_with($file->getFilename(), '.blade.php')) {
                $out[] = $file->getPathname();
            }
        }

        return $out;
    }
}
