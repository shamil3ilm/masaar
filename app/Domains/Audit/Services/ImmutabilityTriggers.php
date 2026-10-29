<?php

declare(strict_types=1);

namespace App\Domains\Audit\Services;

use Illuminate\Support\Facades\DB;

/**
 * Whether the database still refuses to change what the platform keeps:
 * the triggers that freeze an issued invoice and its parts (migration 1814),
 * keep the audit log append-only (1817) and let an audit chain's head only
 * move forward (1851).
 *
 * A migration fails loudly when it cannot create them, but nothing noticed
 * one dropped later - by a restore that left them out, or by someone with
 * the TRIGGER privilege. platform:simulation-preflight and the admin
 * dashboard's audit chain check ask this.
 *
 * MySQL only: the triggers exist nowhere else, so on another database every
 * one is missing.
 */
class ImmutabilityTriggers
{
    /** Every trigger the migrations create, by name. */
    public const EXPECTED = [
        'invoices_issued_frozen',
        'invoices_issued_kept',
        'invoice_lines_issued_insert',
        'invoice_lines_issued_update',
        'invoice_lines_issued_delete',
        'invoice_charges_issued_insert',
        'invoice_charges_issued_update',
        'invoice_charges_issued_delete',
        'invoice_prepayments_issued_insert',
        'invoice_prepayments_issued_update',
        'invoice_prepayments_issued_delete',
        'audit_logs_unchanged',
        'audit_logs_kept',
        'audit_chain_heads_forward',
        'audit_chain_heads_kept',
    ];

    /**
     * The expected triggers this database does not have.
     *
     * @return list<string>
     */
    public function missing(): array
    {
        if (DB::getDriverName() !== 'mysql') {
            return self::EXPECTED;
        }

        $present = DB::table('information_schema.TRIGGERS')
            ->where('TRIGGER_SCHEMA', DB::getDatabaseName())
            ->pluck('TRIGGER_NAME')
            ->map(fn (mixed $name): string => (string) $name)
            ->all();

        return array_values(array_diff(self::EXPECTED, $present));
    }
}
