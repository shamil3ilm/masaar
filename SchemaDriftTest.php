<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Keeps docs/schema.sql equal to the schema the migrations build.
 *
 * Written by hand it described a fraction of the tables. Generated, it is
 * the same text on SQLite and on MySQL (DocumentSchema), so this fails on
 * either the moment a migration adds, drops or changes a column, an index
 * or a key without the file being regenerated:
 *
 *     php artisan platform:schema
 */
class SchemaDriftTest extends TestCase
{
    use RefreshDatabase;

    private const SCHEMA = 'docs/schema.sql';

    public function test_committed_schema_matches_the_migrations(): void
    {
        Artisan::call('platform:schema', ['--print' => true]);

        $this->assertSame(
            $this->normalise((string) file_get_contents(base_path(self::SCHEMA))),
            $this->normalise(Artisan::output()),
            self::SCHEMA.' is out of date with the migrations. Run: php artisan platform:schema',
        );
    }

    private function normalise(string $sql): string
    {
        return trim(str_replace("\r\n", "\n", $sql));
    }
}
