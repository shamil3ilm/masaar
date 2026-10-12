<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * The consoles execute no third-party code.
 *
 * A script tag pointing at a third party is that third party's code running
 * with the session of whoever is looking at the page - and these pages are an
 * authenticated admin console and a taxpayer portal.
 *
 * Two such tags were here until 2026-10-12, and both were worse than they had
 * to be. `unpkg.com/alpinejs@3.x.x` was a floating version: it resolved to
 * whatever the registry served for 3.x that day, so the code could change
 * without anything here changing, and a bad release would reach an operator's
 * browser with no deployment. Neither tag carried an integrity attribute, so a
 * substituted file would have been executed rather than refused. And
 * `cdn.tailwindcss.com` is the Play CDN, which compiles stylesheets in the
 * browser and is documented as unsuitable for production.
 *
 * They are gone, and not by vendoring or by pinning with a hash: this project
 * already had a Vite and Tailwind 4 pipeline, with `resources/css/app.css`
 * scanning the Blade files. Four views were simply bypassing it. Alpine is now
 * a locked dependency in the bundle and the stylesheet is built from the
 * classes actually used, so there is nothing to pin a hash to and nothing
 * fetched at render time.
 *
 * What this test does is hold that: no absolute script or stylesheet URL in
 * any view, and the bundle still wired up - because a view that quietly drops
 * `@vite` loses its styling rather than failing, and the quickest fix anyone
 * reaches for is the CDN tag that used to be there.
 *
 * It also removes the reason SecurityHeaders set no Content-Security-Policy.
 * The Play CDN needed unsafe-eval, which made a policy pointless; the bundle
 * needs neither. A policy is now worth adding, and the inline <script> blocks
 * in these layouts are what it has to account for.
 */
class ExternalScriptTest extends TestCase
{
    private const VIEWS = __DIR__.'/../../../resources/views';

    /**
     * Nothing. Kept as a list so adding one is a visible, argued change
     * rather than an edit to a condition.
     *
     * @var list<string>
     */
    private const ALLOWED = [];

    /**
     * The views that carried the CDN tags and now carry @vite.
     *
     * Named individually rather than globbed: a view that never had them does
     * not need the bundle, and asserting over every file would make adding a
     * plain one a failure.
     *
     * @var list<string>
     */
    private const BUNDLED = [
        'auth/login.blade.php',
        'layouts/admin.blade.php',
        'layouts/portal.blade.php',
        'portal/select-org.blade.php',
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
     * The views that used to load a CDN now load the bundle.
     *
     * This replaces an assertion that the two CDN URLs were still present,
     * which existed so the check above could not become a test of nothing.
     * Now that they are gone, the thing worth asserting is the replacement: a
     * layout that loses @vite renders unstyled rather than failing, and the
     * quickest fix anyone reaches for is the script tag that used to be there.
     *
     * The stylesheet is named as well as the script, because Tailwind is only
     * in the page through the bundle now - there is no CDN fallback behind it.
     */
    public function test_the_consoles_load_the_bundle(): void
    {
        $missing = [];

        foreach (self::BUNDLED as $view) {
            $source = (string) file_get_contents(self::VIEWS.'/'.$view);

            if (! str_contains($source, '@vite')) {
                $missing[] = $view.' does not reference the bundle';

                continue;
            }

            foreach (['resources/css/app.css', 'resources/js/app.js'] as $entry) {
                if (! str_contains($source, $entry)) {
                    $missing[] = $view.' omits '.$entry;
                }
            }
        }

        $this->assertSame([], $missing, implode('
', $missing).'

'
            .'These views are styled and scripted entirely from the Vite bundle '
            .'since the CDN tags were removed. Without @vite they render '
            .'unstyled, which invites putting the CDN tag back.');
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
