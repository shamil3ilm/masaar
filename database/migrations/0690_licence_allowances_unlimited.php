<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Allowances follow the platform's default unless set for the customer.
 *
 * Until now a licence's invoices a month and API calls a day were copied
 * from its tier when it was issued. The owner's decision is that allowances
 * are set per customer, and the platform's default is unlimited for now. A
 * licence still holding exactly its tier's old figure was never given one of
 * its own, so it takes the default; any other figure was chosen for that
 * customer and is kept.
 */
return new class extends Migration
{
    /** What each tier used to copy onto its licences. */
    private const OLD = [
        'starter' => ['invoices_per_month' => 100, 'calls_per_day' => 5000],
        'professional' => ['invoices_per_month' => 1000, 'calls_per_day' => 20000],
        'enterprise' => ['invoices_per_month' => 10000, 'calls_per_day' => 100000],
    ];

    /** The column's ceiling, which a licence reads as unlimited (License::UNLIMITED). */
    private const UNLIMITED = 4294967295;

    public function up(): void
    {
        foreach (self::OLD as $tier => $limits) {
            foreach ($limits as $column => $old) {
                DB::table('licenses')->where('tier', $tier)->where($column, $old)->update([$column => self::UNLIMITED]);
            }
        }
    }

    public function down(): void
    {
        foreach (self::OLD as $tier => $limits) {
            foreach ($limits as $column => $old) {
                DB::table('licenses')->where('tier', $tier)->where($column, self::UNLIMITED)->update([$column => $old]);
            }
        }
    }
};
