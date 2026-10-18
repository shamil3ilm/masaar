<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Write docs/schema.sql from the migrated database.
 *
 * The file was written by hand and described a fraction of the tables, with
 * columns that had since moved or gone. Read from the schema the migrations
 * build instead, it cannot drift: SchemaDriftTest runs this and fails when
 * the committed file differs.
 *
 * The same text on MySQL and on SQLite, so the test holds on both: each
 * column's type is its kind (string, text, integer, decimal, boolean,
 * timestamp, date, time, binary) rather than either engine's spelling of
 * it, columns are listed key first and then by name (MySQL keeps the
 * position a migration asked for, SQLite appends), and the index MySQL adds
 * by itself under a foreign key - which SQLite has no need of - is left out,
 * the key being listed itself. The migrations are where lengths, precision
 * and enum values are read.
 */
class DocumentSchema extends Command
{
    protected $signature = 'masaar:schema
                            {--path=docs/schema.sql : Where to write}
                            {--print : Write to stdout instead of the file}';

    protected $description = 'Write the schema reference from the migrated database';

    /** type_name => kind, for both engines. */
    private const KINDS = [
        'char' => 'string', 'varchar' => 'string', 'enum' => 'string', 'set' => 'string',
        'text' => 'text', 'tinytext' => 'text', 'mediumtext' => 'text', 'longtext' => 'text', 'json' => 'text', 'clob' => 'text',
        'integer' => 'integer', 'int' => 'integer', 'bigint' => 'integer', 'mediumint' => 'integer', 'smallint' => 'integer', 'tinyint' => 'integer',
        'decimal' => 'decimal', 'numeric' => 'decimal', 'double' => 'decimal', 'float' => 'decimal', 'real' => 'decimal',
        'timestamp' => 'timestamp', 'datetime' => 'timestamp', 'date' => 'date', 'time' => 'time', 'year' => 'integer',
        'blob' => 'binary', 'binary' => 'binary', 'varbinary' => 'binary', 'longblob' => 'binary',
    ];

    public function handle(): int
    {
        $tables = array_values(array_filter(
            Schema::getTableListing(Schema::getCurrentSchemaName(), false),
            fn (string $table): bool => ! str_starts_with($table, 'sqlite_'),
        ));
        sort($tables);

        $sql = "-- Masaar database schema, generated from the migrations by `php artisan masaar:schema`.\n"
            ."-- Edit the migrations, not this file. Column types are kinds, the same on MySQL and SQLite;\n"
            ."-- tests/Feature/Architecture/TableTimestamps.php says what each table is for.\n";

        foreach ($tables as $table) {
            $sql .= "\n".$this->createTable($table);
        }

        if ($this->option('print')) {
            $this->output->write($sql);

            return self::SUCCESS;
        }

        file_put_contents(base_path((string) $this->option('path')), $sql);
        $this->info('Wrote '.$this->option('path').' ('.count($tables).' tables).');

        return self::SUCCESS;
    }

    private function createTable(string $table): string
    {
        $indexes = Schema::getIndexes($table);
        $foreign = Schema::getForeignKeys($table);
        $primary = $this->primary($indexes);

        $columns = Schema::getColumns($table);
        usort($columns, fn (array $a, array $b): int => [! in_array($a['name'], $primary, true), $a['name']] <=> [! in_array($b['name'], $primary, true), $b['name']]);

        $lines = array_map(fn (array $column): string => '    '.$this->column($column), $columns);

        if ($primary !== []) {
            $lines[] = '    PRIMARY KEY ('.$this->names($primary).')';
        }

        foreach ($this->secondary($indexes, $foreign) as $index) {
            $lines[] = '    '.($index['unique'] ? 'UNIQUE ' : '').'INDEX `'.$index['name'].'` ('.$this->names($index['columns']).')';
        }

        usort($foreign, fn (array $a, array $b): int => $a['columns'] <=> $b['columns']);

        foreach ($foreign as $key) {
            $lines[] = '    FOREIGN KEY ('.$this->names($key['columns']).') REFERENCES `'.$key['foreign_table'].'` ('.$this->names($key['foreign_columns']).')'
                .$this->action('DELETE', (string) $key['on_delete']).$this->action('UPDATE', (string) $key['on_update']);
        }

        return 'CREATE TABLE `'.$table."` (\n".implode(",\n", $lines)."\n);\n";
    }

    /**
     * @param  array<string, mixed>  $column
     */
    private function column(array $column): string
    {
        $name = strtolower((string) $column['type_name']);
        $kind = $name === 'tinyint' && str_contains(strtolower((string) $column['type']), 'tinyint(1)') ? 'boolean' : (self::KINDS[$name] ?? $name);

        $line = '`'.$column['name'].'` '.strtoupper($kind);

        if (($column['generation'] ?? null) !== null) {
            return $line.' GENERATED';
        }

        $line .= $column['nullable'] ? ' NULL' : ' NOT NULL';

        if ($column['auto_increment']) {
            $line .= ' AUTO_INCREMENT';
        }

        $default = $this->default($column['default'], $kind);

        return $default === null ? $line : $line.' DEFAULT '.$default;
    }

    /**
     * A default as both engines report it: SQLite quotes a literal, MySQL
     * does not, and MySQL writes a decimal's zero as 0.00.
     */
    private function default(mixed $default, string $kind): ?string
    {
        if ($default === null) {
            return null;
        }

        $value = trim((string) $default);

        if (strtoupper($value) === 'NULL') {
            return null;
        }

        if (preg_match("/^'(.*)'$/s", $value, $quoted) === 1) {
            $value = str_replace("''", "'", $quoted[1]);
        }

        if (in_array(strtolower($value), ['current_timestamp', 'current_timestamp()'], true)) {
            return 'CURRENT_TIMESTAMP';
        }

        if (in_array($kind, ['integer', 'decimal', 'boolean'], true) && is_numeric($value)) {
            return (string) (0 + $value);
        }

        return "'".str_replace("'", "''", $value)."'";
    }

    /**
     * @param  list<array<string, mixed>>  $indexes
     * @return list<string>
     */
    private function primary(array $indexes): array
    {
        foreach ($indexes as $index) {
            if ($index['primary'] || str_starts_with((string) $index['name'], 'sqlite_autoindex_')) {
                return $index['columns'];
            }
        }

        return [];
    }

    /**
     * The table's own indexes, by name, without the primary key and without
     * an index that only serves a foreign key on its column.
     *
     * @param  list<array<string, mixed>>  $indexes
     * @param  list<array<string, mixed>>  $foreign
     * @return list<array<string, mixed>>
     */
    private function secondary(array $indexes, array $foreign): array
    {
        $keyed = array_map(fn (array $key): array => $key['columns'], $foreign);

        $kept = array_values(array_filter($indexes, fn (array $index): bool => ! $index['primary']
            && ! str_starts_with((string) $index['name'], 'sqlite_autoindex_')
            && ! (! $index['unique'] && str_ends_with((string) $index['name'], '_foreign') && in_array($index['columns'], $keyed, true))));

        usort($kept, fn (array $a, array $b): int => $a['name'] <=> $b['name']);

        return $kept;
    }

    private function action(string $on, string $action): string
    {
        $action = strtoupper($action);

        return in_array($action, ['', 'NO ACTION', 'RESTRICT'], true) ? '' : " ON {$on} {$action}";
    }

    /**
     * @param  list<string>  $names
     */
    private function names(array $names): string
    {
        return implode(', ', array_map(fn (string $name): string => '`'.$name.'`', $names));
    }
}
