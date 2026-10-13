<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Tests\TestCase;

/**
 * The navigation does not eat the screen it is navigating.
 *
 * Both layouts put a 256px sidebar in a flex row and left it there. On a
 * 390px phone that is two thirds of the width: the admin console had about
 * 134px of content beside it, and the portal had none to spare. The admin
 * sidebar had a toggle, but it started open and collapsed only to 80px, so
 * the narrow case it existed for was never reachable; the portal had no
 * toggle anywhere, so its navigation could not be got out of the way at all.
 *
 * Below md the sidebar is now a drawer, over the content, with a backdrop.
 *
 * Measuring this properly means a browser at a given width, which this suite
 * has no business starting. What is asserted here is the structure that the
 * measurement depends on - and in particular min-w-0, which is subtle enough
 * to be removed by someone tidying up:
 *
 * A flex child defaults to min-width:auto and so refuses to shrink below its
 * content. Without min-w-0 on <main>, the widest table pushed main wider than
 * the screen, the page itself scrolled sideways, and the overflow-x-auto that
 * x-ui.table adds had nothing to do - the table was never the thing that
 * overflowed, main was.
 */
class ResponsiveLayoutTest extends TestCase
{
    private const VIEWS = __DIR__.'/../../../resources/views';

    private const LAYOUTS = ['layouts/admin.blade.php', 'layouts/portal.blade.php'];

    /**
     * Every layout can put its navigation away.
     */
    public function test_a_layout_can_hide_its_nav(): void
    {
        foreach (self::LAYOUTS as $layout) {
            $source = $this->source($layout);

            $this->assertMatchesRegularExpression('/x-data="(sidebar|drawer)"/', $source,
                $layout.' has no sidebar component, so nothing can open or close it.');

            $this->assertStringContainsString('@click="toggle"', $source,
                $layout.' has no toggle.');

            $this->assertStringContainsString('aria-controls="sidebar"', $source,
                $layout.' has a toggle that does not say what it toggles.');

            $this->assertStringContainsString(':aria-expanded="expanded"', $source,
                $layout.' has a toggle that does not say whether it is open.');

            $this->assertStringContainsString('@click="close"', $source,
                $layout.' has no backdrop to tap - on a narrow screen that is the only way out.');
        }
    }

    /**
     * And its content can shrink to the screen it is on.
     */
    public function test_main_can_shrink(): void
    {
        foreach (self::LAYOUTS as $layout) {
            $this->assertMatchesRegularExpression('/<main[^>]*\bclass="[^"]*min-w-0/', $this->source($layout),
                $layout.': <main> needs min-w-0. A flex child will not shrink below its content without '
                .'it, so the widest table scrolls the whole page sideways instead of scrolling itself.');
        }
    }

    /**
     * Nothing claims a width a phone has not got.
     *
     * 320px is the narrowest screen worth supporting, and padding takes some
     * of that. A width wider than this belongs behind a breakpoint - the
     * reason form on admin/kill-switches was w-72, which is 288px before the
     * button beside it, and scrolled that page sideways on every phone.
     */
    public function test_no_width_exceeds_a_phone(): void
    {
        $offenders = [];

        foreach ($this->views() as $relative => $contents) {
            preg_match_all('/(?<![a-z:-])(w-(?:72|80|96)|min-w-\[(\d+)px\])/', $contents, $matches, PREG_SET_ORDER);

            foreach ($matches as $match) {
                // A min-width a phone can still hold is fine; it is what lets
                // a filter stay usable rather than collapsing to nothing.
                if (isset($match[2]) && (int) $match[2] <= 280) {
                    continue;
                }

                $offenders[] = $relative.': '.$match[1];
            }
        }

        $this->assertSame([], $offenders,
            'These widths are wider than a 320px screen and are not behind a breakpoint. '
            .'Write them as w-full sm:w-72, so they only claim the width once there is room.');
    }

    private function source(string $relative): string
    {
        return (string) file_get_contents(self::VIEWS.'/'.$relative);
    }

    /** @return array<string, string> relative path => contents */
    private function views(): array
    {
        $found = [];

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::VIEWS, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen(self::VIEWS) + 1));
            $found[$relative] = (string) file_get_contents($file->getPathname());
        }

        ksort($found);

        return $found;
    }
}
