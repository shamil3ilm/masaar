<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Tests\TestCase;

/**
 * Every screen draws from the design system, and the exceptions only shrink.
 *
 * Before the design system the views held 455 text-gray-500s, each beside a
 * dark: pair someone had to remember, eleven class strings for one button,
 * and colours decided screen by screen. The tokens in resources/css/app.css
 * and the components in components/ui replaced them; these ratchets keep a
 * screen from drifting back, one hand-picked colour at a time.
 *
 * Each ceiling is the count when it was set. Removing one of the exceptions
 * fails here until the ceiling is lowered to match, so the number can only go
 * down. Raising one needs a reason written beside it.
 */
class DesignSystemTest extends TestCase
{
    private const VIEWS = __DIR__.'/../../../resources/views';

    /**
     * Views allowed to write a colour as hex.
     *
     * The PDF and the printed invoice are drawn for paper by a renderer that
     * reads none of the stylesheet; an email is read by mail clients that
     * honour little but inline style; and the logo is the brand's own
     * geometry, with the hex only as the fallback beneath its classes. A
     * country draws its own paper invoice and PDF under countries/x/print/.
     */
    private const HEX_ALLOWED = ['pdf/', 'emails/', 'portal/invoices/print.blade.php', 'countries/saudi-arabia/print/', 'countries/united-arab-emirates/print/', 'components/logo.blade.php'];

    /** Hex colours in every other view. */
    private const HEX_CEILING = 0;

    /**
     * Hand-written <button>, <input>, <select> and <textarea> outside the
     * components. A hidden input is not drawn, and is not counted.
     *
     * The two left are the sidebar toggles in the admin and portal layouts:
     * their Alpine bindings are asserted by ResponsiveLayoutTest as written.
     */
    private const CONTROL_CEILING = 2;

    /**
     * Stock Tailwind hue ramps (gray-500, red-600, ...) outside the
     * components, the PDF, the printed invoice and the emails. A screen asks
     * for a role - text-muted, bg-danger-soft - and the role decides the
     * colour, in light and dark and under an organization's theme.
     *
     * White is not a hue: it is text on the navy surfaces, and it is allowed.
     */
    private const PALETTE_CEILING = 0;

    private const STOCK = '/(?<![\w-])(?:[a-z-]+:)*(?:bg|text|border|divide|ring|outline|from|to|via|fill|stroke|placeholder|accent|decoration)-(?:slate|gray|zinc|neutral|stone|red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose)-\d{2,3}(?:\/\d+)?(?![\w-])/';

    /** Where a stock ramp is still the right thing to write. */
    private const PALETTE_ALLOWED = [
        'pdf/', 'emails/', 'portal/invoices/print.blade.php', 'countries/saudi-arabia/print/', 'countries/united-arab-emirates/print/', 'components/',
        // The home page's invoice is a picture of paper and keeps paper's
        // colours in either mode; its route and stops are the logo's teal.
        'home/',
        // The style guide shows the brand ramps themselves.
        'admin/partials/design-specimens.blade.php',
    ];

    public function test_hex_colours_only_shrink(): void
    {
        $found = $this->tally('/#[0-9A-Fa-f]{6}\b|#[0-9A-Fa-f]{3}\b(?![0-9A-Fa-f-])/', self::HEX_ALLOWED);

        $this->assertRatchet($found, self::HEX_CEILING, 'hex colours',
            'Use a colour role from resources/css/app.css (bg-surface, text-muted, text-danger ...) instead.');
    }

    public function test_raw_controls_only_shrink(): void
    {
        $found = [];

        foreach ($this->views(['components/ui/', 'pdf/', 'emails/']) as $relative => $source) {
            preg_match_all('/<(?:button|select|textarea)\b|<input\b(?![^>]*type="hidden")/s', $source, $matches);

            if ($matches[0] !== []) {
                $found[$relative] = count($matches[0]);
            }
        }

        $this->assertRatchet($found, self::CONTROL_CEILING, 'hand-written controls',
            'Use x-ui.button, x-ui.link-button, x-ui.input, x-ui.select, x-ui.textarea, x-ui.checkbox, '
            .'x-ui.radio or x-ui.switch, which are 44px tall, labelled and focus-ringed.');
    }

    public function test_stock_colours_only_shrink(): void
    {
        $found = $this->tally(self::STOCK, self::PALETTE_ALLOWED);

        $this->assertRatchet($found, self::PALETTE_CEILING, 'stock palette colours',
            'Ask for a role (text-muted, bg-sunken, border-line, text-accent, bg-warning-soft ...) so the '
            .'colour follows dark mode and the organization\'s theme.');
    }

    /**
     * The same rule, run against something that breaks it, so a pattern that
     * stopped matching anything would be noticed.
     */
    public function test_the_patterns_catch_offenders(): void
    {
        $this->assertSame(1, preg_match(self::STOCK, '<p class="text-gray-500 dark:text-gray-400">'));
        $this->assertSame(1, preg_match(self::STOCK, '<p class="hover:bg-red-600">'));
        $this->assertSame(0, preg_match(self::STOCK, '<p class="text-muted bg-danger-soft text-white">'));
    }

    /**
     * @param  array<string, int>  $found  file => count
     */
    private function assertRatchet(array $found, int $ceiling, string $what, string $instead): void
    {
        $total = array_sum($found);
        $where = implode("\n  ", array_map(fn (string $file, int $n): string => "{$file}: {$n}", array_keys($found), $found));

        $this->assertLessThanOrEqual($ceiling, $total,
            "{$total} {$what} in the views, over the ceiling of {$ceiling}. {$instead}\n  {$where}");

        $this->assertSame($ceiling, $total,
            "There are {$total} {$what} now, under the ceiling of {$ceiling}. Lower the ceiling in "
            .static::class.' to '.$total.' so it cannot grow back.');
    }

    /**
     * @param  list<string>  $allowed
     * @return array<string, int>
     */
    private function tally(string $pattern, array $allowed): array
    {
        $found = [];

        foreach ($this->views($allowed) as $relative => $source) {
            $matches = preg_match_all($pattern, $source);

            if ($matches > 0) {
                $found[$relative] = $matches;
            }
        }

        return $found;
    }

    /**
     * Every view not under one of the given prefixes, without its Blade
     * comments - a comment describes a class, it does not use it.
     *
     * @param  list<string>  $except
     * @return array<string, string>
     */
    private function views(array $except): array
    {
        $found = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::VIEWS, \FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen(self::VIEWS) + 1));

            foreach ($except as $prefix) {
                if (str_starts_with($relative, $prefix)) {
                    continue 2;
                }
            }

            $found[$relative] = (string) preg_replace('/\{\{--.*?--\}\}/s', '', (string) file_get_contents($file->getPathname()));
        }

        ksort($found);

        return $found;
    }
}
