<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use App\Domains\Organization\Concerns\RecordsActors;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * The migrated schema keeps what each table's class asks of it
 * (TableTimestamps): its timestamps, its tenant, its actors, how it arrived,
 * its external id and its request id - and a compliance record or a log
 * never has deleted_at.
 *
 * Read from the real schema after migration, on SQLite and on MySQL alike,
 * so a table another branch adds is caught the moment it merges, with a
 * message saying what to add.
 */
class TableTimestampsTest extends TestCase
{
    use RefreshDatabase;

    private const APP = __DIR__.'/../../../app';

    private const CLASSES = ['a', 'b', 'c', 'd', 'framework'];

    private const TENANTS = ['organization', 'branch', 'either', 'global'];

    /** The columns attribution fills, which no request may set. */
    private const ATTRIBUTION = ['created_by', 'updated_by', 'deleted_by', 'created_via', 'request_id'];

    /**
     * Fillable before RecordsActors existed, each set by its service from
     * the actor it is handed. A new one is not allowed.
     */
    private const FILLABLE_BEFORE = [
        'Theme::updated_by' => 'ThemeService passes the actor it is given',
        'InvoiceLink::created_by' => 'InvoiceLinks passes the person who made the link',
        'UsageEvent::request_id' => 'UsageEvent::record() takes the id ValidateLicense read',
    ];

    public function test_every_table_is_classified(): void
    {
        $missing = [];

        foreach ($this->tables() as $table) {
            if (! isset(TableTimestamps::TABLES[$table])) {
                $missing[] = "{$table} is not classified. Add it to tests/Feature/Architecture/TableTimestamps.php: "
                    ."'{$table}' => ['class' => 'a'|'b'|'c'|'d', 'tenant' => 'organization'|'branch'|'either'|'global'|'parent:<column>', 'actors' => [...]]"
                    .' - (a) entity, (b) removable entity, (c) compliance or financial record, (d) append-only log.';
            } elseif (! in_array(TableTimestamps::TABLES[$table]['class'], self::CLASSES, true)) {
                $missing[] = "{$table} has class '".TableTimestamps::TABLES[$table]['class']."'; use one of a, b, c, d or framework.";
            }
        }

        $this->assertSame([], $missing, implode("\n", $missing));
    }

    public function test_tables_keep_their_class_timestamps(): void
    {
        $this->assertNoProblems(function (string $table, array $spec, array $columns): array {
            $problems = [];
            $legacy = $spec['legacy'] ?? [];
            $class = $spec['class'];

            $required = match ($class) {
                'a' => ['created_at', 'updated_at'],
                'b' => ['created_at', 'updated_at', 'deleted_at'],
                'c' => ($spec['updates'] ?? false) ? ['created_at', 'updated_at'] : ['created_at'],
                'd' => [$spec['event'] ?? 'created_at'],
            };

            foreach ($required as $column) {
                if (! in_array($column, $columns, true)) {
                    $problems[] = "{$table} (class {$class}) has no {$column}. Add it in a new migration: "
                        .match ($column) {
                            'deleted_at' => '$table->softDeletes(), and SoftDeletes on its model',
                            'created_at', 'updated_at' => "\$table->timestamp('{$column}')->nullable(), backfilled from the row's own time columns",
                            default => "\$table->timestamp('{$column}')",
                        }.'.';
                }
            }

            if (in_array($class, ['c', 'd'], true) && in_array('deleted_at', $columns, true) && ! isset($legacy['deleted_at'])) {
                $problems[] = "{$table} (class {$class}) has deleted_at, but a compliance record or a log is never deleted "
                    .'(ZATCA Detailed Guidelines p26, p27). Remove the column, or list it under legacy with why it stays.';
            }

            $appendOnly = $class === 'd' || ($class === 'c' && ! ($spec['updates'] ?? false));

            if ($appendOnly && in_array('updated_at', $columns, true) && ! isset($legacy['updated_at'])) {
                $problems[] = "{$table} (class {$class}) is append-only and has updated_at. Remove it, or give the class c table 'updates' => true if its status changes.";
            }

            return $problems;
        });
    }

    public function test_tables_name_their_tenant(): void
    {
        $this->assertNoProblems(function (string $table, array $spec, array $columns): array {
            $tenant = (string) ($spec['tenant'] ?? '');

            if (str_starts_with($tenant, 'parent:')) {
                $parent = substr($tenant, 7);

                return in_array($parent, $columns, true) ? [] : ["{$table} belongs to its parent through {$parent}, which it does not have."];
            }

            if (! in_array($tenant, self::TENANTS, true)) {
                return ["{$table} declares no tenant. Give it 'tenant' => organization, branch, either, global or parent:<column>."];
            }

            $needed = match ($tenant) {
                'organization', 'either' => ['org_id'],
                'branch' => ['org_id', 'branch_id'],
                'global' => [],
            };

            return array_values(array_map(
                fn (string $column): string => "{$table} is owned by an organization (tenant {$tenant}) and has no {$column}. Add \$table->uuid('{$column}') with a foreign key in a new migration.",
                array_diff($needed, $columns),
            ));
        });
    }

    public function test_tables_keep_their_actors(): void
    {
        $this->assertNoProblems(function (string $table, array $spec, array $columns): array {
            $problems = [];
            $actors = $spec['actors'] ?? [];
            $arriving = $spec['arriving_columns'] ?? [];

            $roles = match ($spec['class']) {
                'a' => ['created_by', 'updated_by'],
                'b' => ['created_by', 'updated_by', 'deleted_by'],
                'c' => ['created_by'],
                'd' => [],
            };

            // A row under another is acted on with it, and a table only the
            // platform writes says why; either way the roles are not owed.
            if (! isset($spec['system']) && ! ($spec['parent'] ?? false)) {
                foreach ($roles as $role) {
                    if (! isset($actors[$role])) {
                        $problems[] = "{$table} (class {$spec['class']}) records no {$role}. Add \$table->uuid('{$role}')->nullable() "
                            ."with a foreign key to users (nullOnDelete) in a new migration, list it under 'actors', and use RecordsActors on its model "
                            ."- or say under 'system' why only the platform writes it.";
                    }
                }
            }

            foreach ($actors as $role => $column) {
                if (! in_array($column, $columns, true) && ! isset($arriving[$column])) {
                    $problems[] = "{$table} lists {$column} as its {$role}, and has no such column. Add \$table->uuid('{$column}')->nullable() with a foreign key to users.";
                }
            }

            return $problems;
        });
    }

    public function test_tables_keep_channel_external_and_correlation(): void
    {
        $this->assertNoProblems(function (string $table, array $spec, array $columns, array $indexes): array {
            $problems = [];

            foreach ($spec['channel'] ?? [] as $column) {
                if (! in_array($column, $columns, true)) {
                    $problems[] = "{$table} can arrive several ways and has no {$column}. Add it in a new migration "
                        .($column === 'created_via' ? "(\$table->string('created_via', 20)->nullable())" : "(\$table->uuid('license_id')->nullable())")
                        .' and fill it with RecordsActors.';
                }
            }

            if (isset($spec['external']) && ! in_array($spec['external'], $columns, true)) {
                $problems[] = "{$table} has no {$spec['external']}, the id an ERP knows it by. Add it, nullable and indexed.";
            }

            if (($spec['external_unique'] ?? null) !== null && ! $this->hasUnique($indexes, $spec['external_unique'])) {
                $problems[] = "{$table} has no unique index on (".implode(', ', $spec['external_unique']).'): one external id per organization and source.';
            }

            if (isset($spec['correlation']) && ! in_array($spec['correlation'], $columns, true)) {
                $problems[] = "{$table} has no {$spec['correlation']}. Add \$table->string('request_id', 64)->nullable() and fill it from Origin::requestId().";
            }

            return $problems;
        });
    }

    /**
     * A removed customer's VAT number, a removed product's SKU: made again,
     * they must not be refused by the row that was removed.
     */
    public function test_removable_tables_let_removed_names_return(): void
    {
        $this->assertNoProblems(function (string $table, array $spec, array $columns, array $indexes): array {
            if ($spec['class'] !== 'b') {
                return [];
            }

            $problems = [];

            foreach ($indexes as $index) {
                // SQLite names a text primary key's own index sqlite_autoindex_*.
                if (! $index['unique'] || $index['primary'] || str_starts_with($index['name'], 'sqlite_autoindex_')) {
                    continue;
                }

                if (in_array('not_deleted', $index['columns'], true) || isset($spec['unique_across_removed'][$index['name']])) {
                    continue;
                }

                $problems[] = "{$table}'s unique index {$index['name']} (".implode(', ', $index['columns']).') still holds a removed row, so a removed one cannot be made again. '
                    .'Rebuild it over (..., not_deleted), the generated column that is 1 while the row is not deleted and null once it is, '
                    ."or list it under 'unique_across_removed' with why the removed row is restored instead.";
            }

            return $problems;
        });
    }

    public function test_only_removable_models_soft_delete(): void
    {
        $problems = [];

        foreach ($this->models() as $class => $table) {
            $spec = TableTimestamps::TABLES[$table] ?? null;

            if ($spec === null || isset($spec['retiring'])) {
                continue;
            }

            $softDeletes = in_array(SoftDeletes::class, class_uses_recursive($class), true);

            if ($spec['class'] === 'b' && ! $softDeletes) {
                $problems[] = class_basename($class)." (table {$table}, class b) does not soft-delete. Add `use SoftDeletes;` to it.";
            }

            if (in_array($spec['class'], ['c', 'd'], true) && $softDeletes) {
                $problems[] = class_basename($class)." (table {$table}, class {$spec['class']}) soft-deletes, but these records are never deleted. Remove SoftDeletes.";
            }
        }

        $this->assertSame([], $problems, implode("\n", $problems));
    }

    /**
     * An append-only model writes no updated_at, whatever its table has.
     */
    public function test_append_only_models_skip_updated_at(): void
    {
        $problems = [];

        foreach ($this->models() as $class => $table) {
            $spec = TableTimestamps::TABLES[$table] ?? null;

            if ($spec === null || ! ($spec['class'] === 'd' || ($spec['class'] === 'c' && ! ($spec['updates'] ?? false)))) {
                continue;
            }

            /** @var Model $model */
            $model = new $class;

            if ($model->usesTimestamps() && $model->getUpdatedAtColumn() !== null) {
                $problems[] = class_basename($class)." (table {$table}) is append-only. Add `public const UPDATED_AT = null;`.";
            }
        }

        $this->assertSame([], $problems, implode("\n", $problems));
    }

    /**
     * Attribution is filled by RecordsActors, never taken from a request.
     */
    public function test_attribution_is_not_mass_assignable(): void
    {
        $problems = [];

        foreach (array_keys($this->models()) as $class) {
            /** @var Model $model */
            $model = new $class;

            foreach (array_intersect($model->getFillable(), self::ATTRIBUTION) as $column) {
                $key = class_basename($class).'::'.$column;

                if (! isset(self::FILLABLE_BEFORE[$key])) {
                    $problems[] = "{$key} is fillable. Attribution is filled by RecordsActors; remove it from \$fillable.";
                }
            }
        }

        $this->assertSame([], $problems, implode("\n", $problems));
    }

    /**
     * What a model's RecordsActors fills is a column its table has.
     */
    public function test_recorded_columns_exist(): void
    {
        $problems = [];

        foreach ($this->models() as $class => $table) {
            if (! in_array(RecordsActors::class, class_uses_recursive($class), true) || ! Schema::hasTable($table)) {
                continue;
            }

            /** @var Model&RecordsActors $model */
            $model = new $class;
            $columns = Schema::getColumnListing($table);
            $soft = in_array(SoftDeletes::class, class_uses_recursive($class), true);

            foreach ($model->attributionColumns() as $role => $column) {
                if ($role === 'deleted_by' && ! $soft) {
                    continue;
                }

                if (! in_array($column, $columns, true)) {
                    $problems[] = class_basename($class)." records {$role} in {$column}, which {$table} does not have.";
                }
            }
        }

        $this->assertSame([], $problems, implode("\n", $problems));
    }

    /**
     * @param  callable(string, array<string, mixed>, list<string>, list<array<string, mixed>>): list<string>  $check
     */
    private function assertNoProblems(callable $check): void
    {
        $problems = [];

        foreach ($this->tables() as $table) {
            $spec = TableTimestamps::TABLES[$table] ?? null;

            if ($spec === null || $spec['class'] === 'framework' || isset($spec['retiring'])) {
                continue;
            }

            array_push($problems, ...$check($table, $spec, Schema::getColumnListing($table), Schema::getIndexes($table)));
        }

        $this->assertSame([], $problems, implode("\n", $problems));
    }

    /**
     * @param  list<array<string, mixed>>  $indexes
     * @param  list<string>  $columns
     */
    private function hasUnique(array $indexes, array $columns): bool
    {
        foreach ($indexes as $index) {
            if ($index['unique'] && $index['columns'] === $columns) {
                return true;
            }
        }

        return false;
    }

    /**
     * The tables of the database under test, by name.
     *
     * @return list<string>
     */
    private function tables(): array
    {
        $tables = array_values(array_filter(
            Schema::getTableListing(Schema::getCurrentSchemaName(), false),
            fn (string $table): bool => ! str_starts_with($table, 'sqlite_'),
        ));

        sort($tables);

        return $tables;
    }

    /**
     * Every Eloquent model in app/, with its table.
     *
     * @return array<class-string<Model>, string>
     */
    private function models(): array
    {
        $found = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::APP)) as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $body = (string) file_get_contents($file->getPathname());

            if (! preg_match('/^namespace\s+([^;]+);/m', $body, $namespace)) {
                continue;
            }

            $class = $namespace[1].'\\'.$file->getBasename('.php');

            if (! class_exists($class) || ! is_subclass_of($class, Model::class) || (new \ReflectionClass($class))->isAbstract()) {
                continue;
            }

            $found[$class] = (new $class)->getTable();
        }

        ksort($found);

        return $found;
    }
}
