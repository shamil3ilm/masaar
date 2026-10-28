<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Jobs;

use App\Domains\Invoice\Models\CsvImport;
use App\Domains\Invoice\Services\CsvImports;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Save a confirmed CSV import's rows: customers, products, or invoices as
 * drafts. Run once - a second run would make every record again - so a
 * failure marks the import failed rather than retrying it.
 */
final class RunCsvImport implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(
        public string $importId,
    ) {}

    public function handle(CsvImports $imports): void
    {
        $import = CsvImport::acrossTenants()->find($this->importId);

        if ($import !== null && $import->status === CsvImport::IMPORTING) {
            $imports->run($import);
        }
    }

    public function failed(?Throwable $e): void
    {
        CsvImport::acrossTenants()->whereKey($this->importId)->where('status', CsvImport::IMPORTING)
            ->update(['status' => CsvImport::FAILED, 'failure' => __('Saving stopped part of the way. Check what was made before importing the file again.'), 'updated_at' => now()]);
    }
}
