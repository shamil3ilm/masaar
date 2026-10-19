<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Nothing the browser draws is left in the browser's own style.
 *
 * A scrollbar, an autofilled field, a date picker, a file button, a colour
 * well and a validation bubble are each drawn by the browser unless a page
 * says otherwise - in the platform's grey, its typography and its language,
 * which is English on an Arabic page more often than not. The design system
 * says otherwise once, in resources/css/app.css and the x-ui components;
 * these keep a screen from going back to the native one.
 */
class BrowserDrawnTest extends TestCase
{
    private const ROOT = __DIR__.'/../../..';

    public function test_the_stylesheet_colours_native_parts(): void
    {
        $css = (string) file_get_contents(self::ROOT.'/resources/css/app.css');

        foreach (['scrollbar-color:', 'scrollbar-width:', '::-webkit-scrollbar-thumb', ':-webkit-autofill', 'accent-color:', 'caret-color:', '::selection', ':focus-visible'] as $rule) {
            $this->assertStringContainsString($rule, $css, "resources/css/app.css no longer styles {$rule}.");
        }
    }

    /**
     * A long list is one you can type into: units of measure, exemption
     * reasons and business types are each dozens long, and a country is one
     * of two hundred and fifty - chosen from a list, never typed as a code.
     */
    public function test_long_lists_are_searchable(): void
    {
        $plain = [];

        foreach ($this->views() as $relative => $source) {
            preg_match_all('/<x-ui\.select[^>]*name="[^"]*(?:unit_code|exempt_code|business_type)[^"]*"[^>]*>/', $source, $selects);

            foreach ($selects[0] as $select) {
                if (! str_contains($select, 'searchable')) {
                    $plain[] = $relative.': '.$select;
                }
            }

            if (preg_match('/<x-ui\.input[^>]*name="[^"]*country_code/', $source) === 1) {
                $plain[] = $relative.': a country typed as a code';
            }
        }

        $this->assertSame([], $plain, 'Use <x-ui.select searchable> for a long list and <x-ui.country-select> for a country.');
    }

    /**
     * A date or a time is entered through its component, never a bare
     * native input: the browser's own calendar is in the browser's
     * language, and knows no Hijri.
     */
    public function test_no_bare_date_or_time_inputs(): void
    {
        $this->assertSame([], $this->using('/type="(?:date|time|datetime-local|month|week)"/', [
            'components/ui/date-input.blade.php', 'components/ui/time-input.blade.php',
        ]), 'Use <x-ui.date-input> or <x-ui.time-input>.');
    }

    /**
     * A file is chosen through the drop zone, which names what it takes and
     * refuses the wrong one before it is sent.
     */
    public function test_no_bare_file_inputs(): void
    {
        $this->assertSame([], $this->using('/type="file"/', ['components/ui/file.blade.php']), 'Use <x-ui.file>.');
    }

    /** A colour is our swatch and hex field, never the platform's well. */
    public function test_no_bare_colour_inputs(): void
    {
        $this->assertSame([], $this->using('/type="color"/', ['components/ui/color-input.blade.php']), 'Use <x-ui.color-input>.');
    }

    /**
     * Hand-pasted <svg> outside the components and the home page's drawings,
     * which only shrinks: an icon is <x-ui.icon name="...">, one outline set
     * at one weight. The ten left are a sort arrow drawn per direction and
     * larger illustrations.
     */
    public function test_inline_icons_only_shrink(): void
    {
        $found = [];

        foreach ($this->views() as $relative => $source) {
            if (preg_match('#^(components|pdf|print|emails|home)/#', $relative) !== 1 && ($count = substr_count($source, '<svg')) > 0) {
                $found[] = $relative.': '.$count;
            }
        }

        $total = array_sum(array_map(fn (string $line): int => (int) substr(strrchr($line, ' '), 1), $found));

        $this->assertLessThanOrEqual(10, $total, "Inline SVGs over the ceiling - use <x-ui.icon>:\n  ".implode("\n  ", $found));
        $this->assertSame(10, $total, 'Fewer inline SVGs now: lower the ceiling in '.static::class.' to '.$total.'.');
    }

    /**
     * Glass in three places and nowhere else (owner, 2026-10-19): the sticky
     * top bars, the scrim behind a dialog, one accent in the hero. Cards,
     * tables, forms, invoices and the portal stay solid, and a blur is only
     * ever the glass utilities', which fall back to solid and respect
     * prefers-reduced-transparency.
     */
    public function test_glass_stays_where_it_was_allowed(): void
    {
        $this->assertSame([], $this->using('/\bbackdrop-(?:blur|filter|saturate)/', []), 'Use glass-bar, glass-scrim or glass-chip from app.css.');

        // The style guide shows all three.
        $guide = 'admin/partials/design-specimens.blade.php';

        $this->assertSame([$guide, 'layouts/admin.blade.php', 'layouts/portal.blade.php', 'layouts/public.blade.php'], $this->using('/\bglass-bar\b/', []));
        $this->assertSame([$guide, 'components/ui/confirm-dialog.blade.php'], $this->using('/\bglass-scrim\b/', []));
        $this->assertSame([$guide, 'home/hero.blade.php'], $this->using('/\bglass-chip\b/', []));
    }

    /**
     * The views, outside those allowed, whose code matches.
     *
     * @param  list<string>  $allowed
     * @return list<string>
     */
    private function using(string $pattern, array $allowed): array
    {
        $found = [];

        foreach ($this->views() as $relative => $source) {
            if (! in_array($relative, $allowed, true) && ! str_starts_with($relative, 'pdf/') && preg_match($pattern, $source) === 1) {
                $found[] = $relative;
            }
        }

        return $found;
    }

    /**
     * Every view, without its Blade comments.
     *
     * @return array<string, string>
     */
    private function views(): array
    {
        $root = self::ROOT.'/resources/views';
        $found = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if (str_ends_with($file->getFilename(), '.blade.php')) {
                $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($root) + 1));
                $found[$relative] = (string) preg_replace('/\{\{--.*?--\}\}/s', '', (string) file_get_contents($file->getPathname()));
            }
        }

        ksort($found);

        return $found;
    }
}
