<?php

declare(strict_types=1);

namespace App\Domains\Billing\Console;

use App\Domains\Billing\Reconciliation\Services\StatementImports;
use Illuminate\Console\Command;

/**
 * Remove imported statement files kept past
 * config('billing.reconciliation.keep_days'). The import and its lines
 * stay. Scheduled daily.
 */
class PruneStatements extends Command
{
    protected $signature = 'finance:prune-statements';

    protected $description = 'Remove imported bank statement and settlement files past their keeping period';

    public function handle(StatementImports $imports): int
    {
        $this->info('Removed '.$imports->prune().' statement files.');

        return self::SUCCESS;
    }
}
