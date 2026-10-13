<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Tests\TestCase;

/**
 * A state has one colour, and one place decides it.
 *
 * Eighteen views decided it inline and they did not agree. Two examples of
 * what that cost:
 *
 * `failed` was orange on portal/submissions and admin/logs and red on
 * admin/queue - and red on all three means `rejected`, the authority refusing
 * the document. So a queue item nobody had answered yet read as one that had
 * been turned down, which is the difference between retrying and issuing a
 * credit note.
 *
 * The certificate pill on admin/organization-detail decided nothing at all:
 * it printed whatever status it was handed on a fixed green background, so a
 * revoked or expired certificate looked healthy.
 *
 * Both are invisible to a feature test on one page, because the defect is the
 * disagreement between pages. So the palette is asserted structurally: the
 * colours live in x-ui.badge and nowhere else.
 *
 * AttributeReadTest scans app/ and not Blade, which is why a mistake in a
 * view renders as empty space and the suite passes over it. This test exists
 * in that gap.
 */
class BadgeColourTest extends TestCase
{
    private const VIEWS = __DIR__.'/../../../resources/views';

    /** The pill palette: a tinted background with text dark enough to sit on it. */
    private const PALETTE = '/bg-[a-z]+-100\s+text-[a-z]+-800/';

    /**
     * Only the badge names a pill colour.
     *
     * A tinted circle behind an icon is not a pill and is left alone - it
     * carries no state, and its text is -600. The pair this looks for is the
     * pill, and the pill is the thing that was disagreeing.
     */
    public function test_one_place_decides_a_colour(): void
    {
        $offenders = [];

        foreach ($this->views() as $relative => $contents) {
            if (str_starts_with($relative, 'components/ui/')) {
                continue;
            }

            if (preg_match(self::PALETTE, $contents) === 1) {
                $offenders[] = $relative;
            }
        }

        $this->assertSame([], $offenders,
            'These views decide a pill colour themselves. Use <x-ui.badge :state="..." />, '
            .'or :tone for a pill whose subject is not a state.');
    }

    /**
     * And none of them keeps a lookup table of its own.
     *
     * admin/organization-detail built a six-entry $stateColors map in an @php
     * block, which agreed with nothing: `submitted` was indigo there and blue
     * everywhere else, and `queued` grey rather than blue.
     */
    public function test_no_view_maps_a_state(): void
    {
        $offenders = [];

        foreach ($this->views() as $relative => $contents) {
            if (str_starts_with($relative, 'components/ui/')) {
                continue;
            }

            if (preg_match('/\$(state|status)Colo(u)?rs|\$colo(u)?rClass/i', $contents) === 1) {
                $offenders[] = $relative;
            }
        }

        $this->assertSame([], $offenders, 'These views keep their own state-to-colour map.');
    }

    /**
     * The disagreement that started this, asserted as behaviour.
     *
     * No answer yet and refused are not the same thing and must not look the
     * same. This is the one assertion here that would survive the structural
     * ones being deleted.
     */
    public function test_failed_and_rejected_differ(): void
    {
        $failed = $this->badge('failed');
        $rejected = $this->badge('rejected');

        $this->assertNotSame($failed, $rejected);
        $this->assertStringContainsString('amber', $failed);
        $this->assertStringContainsString('red', $rejected);
    }

    /**
     * A state the map has never heard of is grey, not invisible.
     *
     * The inline versions emitted a pill with no background for anything they
     * did not list, so adding a state to the database made it render as grey
     * text on white - which reads as a rendering fault rather than a state.
     */
    public function test_an_unknown_state_is_grey(): void
    {
        $rendered = $this->badge('something_new');

        $this->assertStringContainsString('bg-gray-100', $rendered);
        $this->assertStringContainsString('Something new', $rendered);
    }

    /**
     * An empty state still renders something readable.
     *
     * Several call sites pass a column that is nullable.
     */
    public function test_a_missing_state_says_unknown(): void
    {
        $this->assertStringContainsString('Unknown', $this->badge(null));
    }

    private function badge(?string $state): string
    {
        return (string) $this->blade('<x-ui.badge :state="$state" />', ['state' => $state]);
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
