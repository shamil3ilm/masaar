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
