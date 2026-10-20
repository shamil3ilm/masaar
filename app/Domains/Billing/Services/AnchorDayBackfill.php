<?php

declare(strict_types=1);

namespace App\Domains\Billing\Services;

use Illuminate\Support\Facades\DB;

/**
 * The day of the month for recurring costs saved before they kept one
 * (migration 1595): where their schedule started - the first bill they
 * drafted, or their next date when they drafted none. Only rows without a
 * day are touched, so running it again changes nothing.
 *
 * Plain queries, not the models: a migration runs against the schema of
 * its own time.
 */
final class AnchorDayBackfill
{
    public function __invoke(): void
    {
        foreach (DB::table('recurring_expenses')->whereNull('anchor_day')->get(['id', 'next_due_on']) as $recurring) {
            $start = DB::table('expenses')->where('recurring_expense_id', $recurring->id)->min('expense_date') ?? $recurring->next_due_on;

            DB::table('recurring_expenses')->where('id', $recurring->id)->update(['anchor_day' => (int) substr((string) $start, 8, 2)]);
        }
    }
}
