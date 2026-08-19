<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * A model may only declare fields its table has.
 *
 * Eloquent does not check. A fillable name with no column is accepted on the
 * way in and then handed to the database, which refuses the whole write — so
 * the field is not merely ignored, it takes the row with it. A cast on a
 * missing column is quieter still: it reads as null forever.
 *
 * FtaSubmission declared reference, validation_status, warnings and errors
 * while its table had fta_submission_id, fta_validation_status, fta_warnings
 * and fta_errors. Every write of an FTA response would have been rejected, and
 * the status poll that reads $submission->reference saw null and returned
 * early, so a submission awaiting review was never chased.
 *
 * This is the write-side half of the same divergence that put
 * clearance_confirmed_at, zatca_certificate and is_suspended into code with no
 * column behind them.
 */
class ModelColumnTest extends TestCase
{
    // Without the schema there is nothing to compare against and every model
    // is skipped, which reads as a pass.
    use RefreshDatabase;

    private const MODELS = __DIR__.'/../../../app';

    /**
     * @return list<class-string<Model>>
     */
    private function models(): array
    {
        $found = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::MODELS));

        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $body = (string) file_get_contents($file->getPathname());

            if (! preg_match('/^namespace\s+([^;]+);/m', $body, $namespace)) {
                continue;
            }

            $class = $namespace[1].'\\'.$file->getBasename('.php');

            if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
                continue;
            }

            $found[] = $class;
        }

        sort($found);

        return $found;
    }

    public function test_fillable_names_only_real_columns(): void
    {
        $this->assertSame([], $this->missingFields(
            fn (Model $model) => $model->getFillable()
        ));
    }

    /**
     * A cast on a column that does not exist never runs, and the attribute it
     * describes reads as null.
     */
    public function test_casts_name_only_real_columns(): void
    {
        $this->assertSame([], $this->missingFields(
            fn (Model $model) => array_keys($model->getCasts())
        ));
    }

    /**
     * @param  callable(Model): list<string>  $fields
     * @return list<string>
     */
    private function missingFields(callable $fields): array
    {
        $missing = [];

        foreach ($this->models() as $class) {
            $model = new $class;
            $table = $model->getTable();

            if (! Schema::hasTable($table)) {
                continue;
            }

            $columns = Schema::getColumnListing($table);

            foreach ($fields($model) as $field) {
                if (in_array($field, $columns, true)) {
                    continue;
                }

                // An attribute a mutator or accessor defines is not a column
                // and does not need to be one.
                $studly = Str::studly($field);

                if (method_exists($model, "set{$studly}Attribute")
                    || method_exists($model, "get{$studly}Attribute")
                    || method_exists($model, $field)) {
                    continue;
                }

                $missing[] = class_basename($class)."::\${$field} has no column on {$table}";
            }
        }

        sort($missing);

        return $missing;
    }
}
