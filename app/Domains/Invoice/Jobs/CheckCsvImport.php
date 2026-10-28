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
 * Check a large CSV import's rows, saving nothing, while the page says it is
 * being checked. A small file is checked in the request instead.
 */
final class CheckCsvImport implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** Checking writes nothing but the import's own row, so a retry is safe. */
    public int $tries = 2;

    public int $timeout = 600;

    public function __construct(
        public string $importId,
    ) {}

    public function handle(CsvImports $imports): void
    {
        $import = CsvImport::acrossTenants()->find($this->importId);

        if ($import !== null && $import->status === CsvImport::CHECKING) {
            $imports->check($import);
        }
    }

    public function failed(?Throwable $e): void
    {
        CsvImport::acrossTenants()->whereKey($this->importId)->where('status', CsvImport::CHECKING)
            ->update(['status' => CsvImport::FAILED, 'failure' => __('The file could not be checked. Upload it again.'), 'updated_at' => now()]);
    }
}
