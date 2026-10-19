<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * A message, a date, a sum of money and a labelled control each have one
 * component, and the screens use it.
 *
 * The audit behind this found twenty-five messages drawn by hand in a dozen
 * class strings - five of them a second copy of the form errors the layout
 * already lists - dates written five ways (2026-10-14, Oct 14, 2026, 14 Oct
 * 13:05, a translated month, and a fractional "3.4521 day(s)"), two activity
 * screens printing SAR after every total whatever the invoice's currency,
 * and a hundred and seventeen controls labelled with a loose <label for> in
 * three styles. Each looked fine on its own screen; side by side they were
 * four products.
 *
 * Read from the view source: what is asserted is what the view was written
 * with, so the rule holds on branches no fixture renders.
 */
class ComponentUseTest extends TestCase
{
    private const VIEWS = __DIR__.'/../../../resources/views';

    /** Documents and emails are typeset for paper and mail clients, not the screen. */
    private const OFF_SCREEN = '#^(components/ui/|pdf/|emails/)|/print/#';

    public function test_messages_use_the_alert(): void
    {
        $allowed = [
            // A support message from staff marked internal: a tinted bubble
            // in a thread, not a message about the page.
            'components/support-thread.blade.php',
        ];

        $found = $this->matching(
            '/\bborder-(warning|danger|success|info)-line\b[^"]*\bbg-\1-soft\b|\bbg-(warning|danger|success|info)-soft\b[^"]*\bborder-\2-line\b/',
            $allowed,
        );

        $this->assertSame([], $found,
            'These views draw a message by hand. Use <x-ui.alert tone="...">: its tone, icon and role '
            .'come with it, and a form\'s errors are already listed by the layout\'s <x-ui.flash />.');
    }

    public function test_dates_use_the_component(): void
    {
        $found = $this->matching('/->(?:format|translatedFormat|isoFormat)\(|->toDateTimeString\(\)/', []);

        $this->assertSame([], $found,
            'These views format a date themselves. Use <x-ui.date :value="..." /> (add time for hours and '
            .'minutes): one form, year-month-day, left to right in either language. Inside a translated '
            .'sentence, ->toDateString() gives the same form.');
    }

    public function test_amounts_use_the_component(): void
    {
        $found = $this->matching('/number_format\((?:[^()]|\([^()]*\))*,\s*2\s*\)|\d\s+SAR\b|\}\}\s*SAR\b/', [
            // The invoice pages' own helper, which prints the same form as text.
            'portal/partials/money.blade.php',
        ]);

        $this->assertSame([], $found,
            'These views format money themselves. Use <x-ui.amount :value="..." :currency="..." />: two '
            .'decimals, grouped thousands, the invoice\'s own currency - never a hardcoded one.');
    }

    /**
     * A control's label is x-ui.field, which nests it: the same type, the
     * same spacing, the error under it and nothing to keep unique.
     */
    public function test_controls_sit_in_a_field(): void
    {
        $allowed = [
            // Draws the invalid state by hand, to show what x-ui.field draws.
            'admin/partials/design-specimens.blade.php',
        ];

        $found = [];

        foreach ($this->views() as $relative => $contents) {
            if (preg_match(self::OFF_SCREEN, $relative) === 1 || in_array($relative, $allowed, true)) {
                continue;
            }

            $loose = (string) preg_replace('#<x-ui\.field\b.*?</x-ui\.field>#s', '', $this->code($contents));

            preg_match_all('/<x-ui\.(?:input|select|textarea)\b(?:[^>"]|"[^"]*")*>/s', $loose, $controls);

            foreach ($controls[0] as $control) {
                if (preg_match('/type="hidden"|aria-label/', $control) !== 1) {
                    $found[] = $relative.': '.substr((string) preg_replace('/\s+/', ' ', $control), 0, 80);
                }
            }
        }

        $this->assertSame([], $found,
            'These controls are not inside <x-ui.field label="...">. A search box with no visible label '
            .'carries aria-label instead.');
    }

    /**
     * The views whose code, comments aside, matches - one line per file.
     *
     * @param  list<string>  $allowed
     * @return list<string>
     */
    private function matching(string $pattern, array $allowed): array
    {
        $found = [];

        foreach ($this->views() as $relative => $contents) {
            if (preg_match(self::OFF_SCREEN, $relative) === 1 || in_array($relative, $allowed, true)) {
                continue;
            }

            if (preg_match_all($pattern, $this->code($contents)) > 0) {
                $found[] = $relative;
            }
        }

        return $found;
    }

    private function code(string $contents): string
    {
        return (string) preg_replace('/\{\{--.*?--\}\}/s', '', $contents);
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
