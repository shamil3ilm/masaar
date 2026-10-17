<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Tests\TestCase;

/**
 * A list on a phone collapses each row to its key fields and opens it in
 * place, and every list gets that from x-ui.table rather than markup of its
 * own.
 *
 * The behaviour is resources/js/ui.js's; what can be asserted here is that
 * every list hands it what it needs - which columns are key, and the words
 * for its disclosure button - and that a section that can wait on a phone
 * says so.
 */
class CompactRowsTest extends TestCase
{
    private const VIEWS = __DIR__.'/../../../resources/views';

    public function test_a_list_carries_the_collapsible_markup(): void
    {
        $html = (string) $this->blade(
            '<x-ui.table :primary="[0, 1, 4, 3]"><x-slot:head><x-ui.th>A</x-ui.th></x-slot:head><tr><td>1</td></tr></x-ui.table>'
        );

        $this->assertStringContainsString('data-collapsible="small"', $html);
        $this->assertStringContainsString('data-primary="0,1,4,3"', $html);
        $this->assertStringContainsString('data-expand-label="Show details"', $html);
        $this->assertStringContainsString('data-collapse-label="Hide details"', $html);
    }

    /**
     * Each list names its own key columns. The default (the first two) is a
     * fallback, not a decision, so a list left on it fails here.
     */
    public function test_every_list_names_its_key_columns(): void
    {
        $undeclared = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::VIEWS, \FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php') || str_contains($file->getPathname(), 'components')) {
                continue;
            }

            preg_match_all('/<x-ui\.table\b[^>]*>/', (string) file_get_contents($file->getPathname()), $tables);

            foreach ($tables[0] as $table) {
                if (! str_contains($table, ':primary=') && ! str_contains($table, 'collapsible="never"')) {
                    $undeclared[] = basename($file->getPathname()).': '.$table;
                }
            }
        }

        $this->assertSame([], $undeclared, 'Name the columns a compact row keeps: <x-ui.table :primary="[0, 1]">.');
    }

    public function test_optional_sections_fold_on_phones(): void
    {
        $html = (string) $this->blade('<x-ui.section title="Notes" collapse>body</x-ui.section>');

        $this->assertMatchesRegularExpression('/<details\s+open\b/', $html);
        $this->assertStringContainsString('data-section-collapse', $html);
        $this->assertStringContainsString('<summary', $html);
    }
}
