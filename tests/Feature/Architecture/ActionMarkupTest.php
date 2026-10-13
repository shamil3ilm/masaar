<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Tests\TestCase;

/**
 * An action is big enough to hit, and a control has a name.
 *
 * Eighteen buttons were written by hand in eleven class strings, all saying
 * nearly the same thing in a different order - rounded against rounded-md,
 * font-medium against font-semibold against neither - and four with no hover
 * state. Two of those strings disagreed about meaning rather than style:
 * Filter was teal on admin/licences and portal/submissions and grey on
 * admin/logs and admin/queue, so the same action had two weights depending on
 * the screen; and Cancel, Reset and Clear were a text link, a text link and a
 * grey fill, for one action.
 *
 * Every one of them was px-4 py-2 text-sm, about thirty-six pixels tall
 * against a usual minimum of forty-four - and these are the Filter, Clear,
 * Issue and Print controls on screens a taxpayer uses on a phone.
 *
 * Separately, three filters on portal/submissions had a visible <label> with
 * no for= and the control as a sibling rather than a child. A sighted user
 * saw a name; anything reading the page got "combo box".
 *
 * None of this is reachable by a feature test - the page renders either way -
 * so it is asserted on the markup.
 */
class ActionMarkupTest extends TestCase
{
    private const VIEWS = __DIR__.'/../../../resources/views';

    /**
     * The fills that mean "this is an action".
     *
     * Deliberately not every bg-*: a tinted card, a stat tile and an icon
     * circle are not actions and are left alone.
     */
    private const FILLS = '/<(?:button|a)\b[^>]*\bclass="[^"]*bg-(?:teal|gray|red)-(?:200|600)/s';

    public function test_one_action_shell(): void
    {
        $offenders = [];

        foreach ($this->views() as $relative => $contents) {
            if (str_starts_with($relative, 'components/ui/')) {
                continue;
            }

            if (preg_match(self::FILLS, $contents) === 1) {
                $offenders[] = $relative;
            }
        }

        $this->assertSame([], $offenders,
            'These views style an action themselves. Use <x-ui.button>, whose variant says what the '
            .'action does - primary, secondary, quiet, outline, danger - rather than what colour it is.');
    }

    /**
     * And the shell is the one that is big enough and shows its focus.
     *
     * min-h-11 is the forty-four pixels, and it is in the component because
     * it was left off fourteen times out of eighteen when it was not.
     */
    public function test_the_shell_is_hittable(): void
    {
        $button = (string) file_get_contents(self::VIEWS.'/components/ui/button.blade.php');

        $this->assertStringContainsString('min-h-11', $button,
            'An action under 44px is hard to hit on a phone.');

        $this->assertStringContainsString('focus-visible:outline', $button,
            'Keyboard focus has to be visible, and the same wherever it lands.');
    }

    /**
     * Every control a person types into has a name something can read.
     *
     * Three ways count, and the codebase uses all three for good reasons:
     * nested inside its <label> (what x-ui.field and the invoice line row
     * do, because a cloned row would duplicate an id), paired by for= and id
     * (the long create forms), or carrying aria-label.
     */
    public function test_every_control_has_a_name(): void
    {
        $offenders = [];

        foreach ($this->views() as $relative => $contents) {
            // A component declares a control; the name belongs to the call
            // site, which this same check covers where that call site lives.
            if (str_starts_with($relative, 'components/ui/')) {
                continue;
            }

            foreach ($this->unnamedControls($contents) as $control) {
                $offenders[] = $relative.': '.$control;
            }
        }

        $this->assertSame([], $offenders,
            'These controls have no accessible name. Wrap them in <x-ui.field label="...">, '
            .'or pair a <label for="x"> with id="x". A placeholder is not a name - it disappears '
            .'as soon as someone types, so it cannot be read back.');
    }

    /**
     * @return list<string>
     */
    private function unnamedControls(string $contents): array
    {
        // Which ids a label on this page claims.
        preg_match_all('/<label\b[^>]*\bfor="([^"]+)"/', $contents, $labelled);
        $claimed = $labelled[1];

        // Anything nested inside a label or an x-ui.field is named by it.
        $loose = (string) preg_replace('#<label\b.*?</label>#s', '', $contents);
        $loose = (string) preg_replace('#<x-ui\.field\b.*?</x-ui\.field>#s', '', $loose);

        preg_match_all('/<(?:input|select|textarea)\b[^>]*>/s', $loose, $controls);

        $unnamed = [];

        foreach ($controls[0] as $control) {
            // A hidden field is not read out, and a submit button's name is
            // its own text.
            if (preg_match('/type="(?:hidden|submit)"/', $control) === 1) {
                continue;
            }

            if (str_contains($control, 'aria-label')) {
                continue;
            }

            if (preg_match('/\bid="([^"]+)"/', $control, $id) === 1 && in_array($id[1], $claimed, true)) {
                continue;
            }

            $unnamed[] = preg_replace('/\s+/', ' ', substr($control, 0, 70)).'...';
        }

        return $unnamed;
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
