<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Tests\TestCase;

/**
 * A table can be read on a phone, and its headings say they are headings.
 *
 * Twelve screens wrote the same table shell by hand, and all twelve made the
 * same two mistakes.
 *
 * Every one put a min-w-full table inside a card with overflow-hidden.
 * min-w-full lets a table grow past its container, which eight columns at
 * px-6 always do on a narrow viewport, and overflow-hidden then clips what
 * grew with nothing to scroll. The right-hand columns were unreachable on a
 * phone - and on admin/organizations, admin/licences and portal/invoices the
 * right-hand column is Actions. Only admin/organization-detail had a scroll
 * area, which is how we know it was an omission rather than a decision.
 *
 * And not one of the seventy-six headings carried scope, so a screen reader
 * reading a cell could not name the column it was in. On a submissions table
 * that is the difference between hearing "rejected" and hearing "State:
 * rejected".
 *
 * Both are properties of markup rather than of behaviour, so they are
 * asserted on the markup. ScreenRenderTest proves the screens still render;
 * this proves they render the right shell.
 */
class TableMarkupTest extends TestCase
{
    private const VIEWS = __DIR__.'/../../../resources/views';

    /**
     * A printed invoice keeps its own tables on purpose.
     *
     * It is standalone HTML with no layout, no card and no scroll area,
     * printed rather than browsed, and its padding is deliberately tighter
     * than a screen's. Wrapping it in x-ui.table would give a printed page a
     * scroll bar. Every printed layout sits in a print/ folder: the shared
     * receipt's, and each country's pages.
     */
    private const PRINTED = '#(^|/)print/#';

    /**
     * Emails are laid out with tables because mail clients support little
     * else; those tables are layout, not data, and there is no scroll area to
     * give them.
     */
    private const EMAILS = 'emails/';

    /**
     * PDF templates are drawn by the PDF renderer, which reads plain tables
     * and none of the components or stylesheet the screens use. A page has
     * no scroll area to give a table.
     */
    private const PDFS = 'pdf/';

    /**
     * One table shell, so a table cannot be built without a scroll area.
     *
     * The scroll area lives inside x-ui.table, which is the only way to make
     * it impossible to forget - it was forgotten eleven times out of twelve.
     */
    public function test_one_table_shell(): void
    {
        $offenders = [];

        foreach ($this->views() as $relative => $contents) {
            if (str_starts_with($relative, 'components/ui/') || preg_match(self::PRINTED, $relative) === 1
                || str_starts_with($relative, self::EMAILS) || str_starts_with($relative, self::PDFS)) {
                continue;
            }

            foreach (['<table', '<thead', '<tbody', '<th '] as $tag) {
                if (str_contains($contents, $tag)) {
                    $offenders[] = $relative.' writes '.$tag;
                }
            }
        }

        $this->assertSame([], $offenders,
            'Use <x-ui.table> with an <x-slot:head> of <x-ui.th> cells. A table built by hand '
            .'has no scroll area, and inside a card with overflow-hidden its right-hand columns '
            .'cannot be reached on a phone.');
    }

    /**
     * And the shell is the one that does the two things right.
     *
     * Asserted here rather than through a rendered page because it is the
     * reason the rule above is worth keeping: if the component stopped
     * emitting these, every table would lose them at once and no screen test
     * would notice.
     */
    public function test_the_shell_scrolls_and_scopes(): void
    {
        $table = $this->source('components/ui/table.blade.php');
        $heading = $this->source('components/ui/th.blade.php');

        $this->assertStringContainsString('overflow-x-auto', $table,
            'x-ui.table must give its table somewhere to scroll.');

        $this->assertStringContainsString('tabindex="0"', $table,
            'A scroll area that cannot be focused can only be scrolled by a pointer.');

        $this->assertStringContainsString('scope="col"', $heading,
            'x-ui.th must say which column it heads.');
    }

    /**
     * Every heading says it is one, including the receipt's.
     */
    public function test_every_heading_has_scope(): void
    {
        $offenders = [];

        foreach ($this->views() as $relative => $contents) {
            preg_match_all('/<th\b(?![^>]*scope=)[^>]*>/', $contents, $matches);

            foreach ($matches[0] as $heading) {
                $offenders[] = $relative.': '.$heading;
            }
        }

        $this->assertSame([], $offenders, 'These headings do not name their column.');
    }

    /**
     * An empty row spans its table.
     *
     * Blade cannot count the headings above it, so the column count is given
     * by hand - which means it goes stale the first time a column is added.
     * A colspan one short leaves "No submissions found" boxed into the left
     * of the table instead of centred under it.
     */
    public function test_an_empty_row_spans_its_table(): void
    {
        $offenders = [];

        foreach ($this->views() as $relative => $contents) {
            preg_match_all('/<x-ui\.table[^>]*>(.*?)<\/x-ui\.table>/s', $contents, $tables);

            foreach ($tables[1] as $table) {
                $columns = preg_match_all('/<x-ui\.th\b/', $table);

                if (preg_match('/<x-ui\.empty :columns="(\d+)"/', $table, $empty) !== 1) {
                    continue;
                }

                if ((int) $empty[1] !== $columns) {
                    $offenders[] = $relative.': '.$columns.' columns, colspan '.$empty[1];
                }
            }
        }

        $this->assertSame([], $offenders, 'These empty rows do not span their table.');
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
