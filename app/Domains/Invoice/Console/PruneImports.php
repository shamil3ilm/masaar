<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Console;

use App\Domains\Invoice\Services\CsvImports;
use Illuminate\Console\Command;

/**
 * Remove uploaded CSV files kept past config('imports.keep_days'). The
 * import's record stays, with what came of it. Scheduled daily.
 */
class PruneImports extends Command
{
    protected $signature = 'imports:prune';

    protected $description = 'Remove uploaded CSV import files past their keeping period';

    public function handle(CsvImports $imports): int
    {
        $removed = $imports->prune();

        $this->info("Removed {$removed} import files.");

        return self::SUCCESS;
    }
}
