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

    /** Fixed or over-wide column counts left in screen views; only goes down. */
    private const GRID_CEILING = 0;

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
     * The sidebar is a column only where the content beside it still fits.
     *
     * At md (768px) a 256px column left the content 448px - less than a
     * phone in landscape - and every grid and banner on the page was laid out
     * for the viewport, not for what was left of it. From lg (1024px) the
     * content keeps at least 704px; below it the sidebar is the drawer. The
     * script's media query and the layouts' lg: classes must say the same
     * width, or one width shows both a column and a menu button, or neither.
     */
    public function test_drawer_below_lg_everywhere(): void
    {
        $script = (string) file_get_contents(__DIR__.'/../../../resources/js/sidebar.js');

        $this->assertStringContainsString("const WIDE = '(min-width: 1024px)'", $script,
            'resources/js/sidebar.js must switch the drawer at lg (1024px), the width the layouts use.');

        foreach (self::LAYOUTS as $layout) {
            $source = $this->source($layout);

            $this->assertStringContainsString('lg:sticky', $source,
                $layout.': the sidebar must become a column at lg, the same width as sidebar.js.');

            $this->assertDoesNotMatchRegularExpression('/\bmd:(sticky|hidden|h-screen|z-auto|top-0)\b/', $source,
                $layout.': the sidebar or its menu bar still switches at md, where the content beside it is 448px.');
        }

        $this->assertStringContainsString('lg:hidden', $this->source('layouts/portal.blade.php'),
            'The portal\'s menu bar must stay until the sidebar is a column.');
    }

    /**
     * The drawer behaves like one: Escape shuts it, and it is out of the way
     * of the keyboard while shut.
     */
    public function test_drawer_escape_and_inert(): void
    {
        foreach (self::LAYOUTS as $layout) {
            $source = $this->source($layout);

            $this->assertStringContainsString('@keydown.escape.window="escape"', $source,
                $layout.': Escape does not close the drawer.');

            $this->assertStringContainsString(':inert="stowed"', $source,
                $layout.': a shut drawer still takes Tab through every link that is off the screen.');

            $this->assertStringContainsString('x-ref="toggle"', $source,
                $layout.': closing the drawer has nowhere to give the focus back to.');
        }
    }

    /**
     * A long word, an IBAN or an id, wraps rather than widening the page.
     */
    public function test_main_wraps_long_words(): void
    {
        foreach (self::LAYOUTS as $layout) {
            $this->assertMatchesRegularExpression('/<main[^>]*\bclass="[^"]*break-words/', $this->source($layout),
                $layout.': <main> needs break-words, or one long id pushes the page past the screen.');
        }
    }

    /**
     * Form grids step down with the width they have.
     *
     * A bare grid-cols-3 keeps three columns on a phone; a form row of five
     * or six columns at lg gets about 110px a field beside the sidebar. Both
     * belong behind a breakpoint where the content is wide enough: sm for
     * two, lg for three, xl for more. Print and e-mail views lay out paper
     * and are not counted. The ceiling only goes down.
     */
    public function test_form_grids_step_down(): void
    {
        $offenders = [];

        foreach ($this->views() as $relative => $contents) {
            if (preg_match('#(^|/)(print|emails?|pdf)/#', $relative) === 1) {
                continue;
            }

            preg_match_all('/(?<![a-z0-9:-])(grid-cols-(?:[3-9]|1[0-2])|(?:sm|md|lg):grid-cols-(?:[5-9]|1[0-2]))(?![\w-])/', $contents, $matches);

            foreach ($matches[1] as $match) {
                $offenders[] = $relative.': '.$match;
            }
        }

        $this->assertLessThanOrEqual(self::GRID_CEILING, count($offenders),
            "Too many columns for the width they get:\n".implode("\n", $offenders)
            ."\nWrite sm:grid-cols-2 lg:grid-cols-3, and keep four or more columns for xl.");
    }

    /**
     * A grid's one phone column is held to the width it has.
     *
     * `grid lg:grid-cols-3` has no columns below lg, so the browser makes one
     * `auto` track, which grows to its widest child: on the invoice page a
     * section pushed the whole column 27px past a 768px screen.
     */
    public function test_one_column_grid_cannot_blow_out(): void
    {
        $css = (string) file_get_contents(__DIR__.'/../../../resources/css/app.css');

        $this->assertMatchesRegularExpression("/\\.grid:not\\(\\[class\\*='grid-flow-col'\\]\\)\\s*\\{\\s*grid-template-columns:\\s*minmax\\(0, 1fr\\);/", $css,
            'resources/css/app.css must give a grid without columns minmax(0, 1fr), or its one column grows past the screen.');
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
