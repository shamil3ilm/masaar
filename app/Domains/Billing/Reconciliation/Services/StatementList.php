<?php

declare(strict_types=1);

namespace App\Domains\Billing\Reconciliation\Services;

use App\Domains\Billing\Reconciliation\Models\StatementImport;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Reconciliation's lists, as the console shows them.
 */
class StatementList
{
    /** The statements brought in, the latest first. */
    public function imports(): LengthAwarePaginator
    {
        return StatementImport::query()->latest()->paginate(25);
    }
}
