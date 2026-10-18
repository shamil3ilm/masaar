<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The indexes the invoice history list's sorts and filters read.
 *
 * Every list query names its organization first, so each index leads with
 * org_id and reads a range of one tenant's rows in order rather than
 * sorting all of them: by issue date, amount and buyer, and by issue date
 * within a document type (the notes list) or a branch (a branch's list).
 * Status, created_at and the invoice number already had theirs.
 *
 * Checked with EXPLAIN on MySQL 8 against 400,000 invoices, half of them one
 * organization's: each sort reads its index backwards and stops at the
 * page, a few milliseconds where it had been a sort of 200,000 rows. A
 * search inside a name still reads the organization's rows, as a contains
 * search must.
 */
return new class extends Migration
{
    /** @var array<string, array<string, list<string>>> table => index => columns */
    private const INDEXES = [
        'invoices' => [
            'invoices_org_issue_date_idx' => ['org_id', 'issue_date'],
            'invoices_org_total_idx' => ['org_id', 'total'],
            'invoices_org_buyer_idx' => ['org_id', 'buyer_name'],
            'invoices_org_document_date_idx' => ['org_id', 'document_type', 'issue_date'],
            'invoices_org_branch_date_idx' => ['org_id', 'branch_id', 'issue_date'],
        ],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            Schema::table($table, function (Blueprint $blueprint) use ($indexes) {
                foreach ($indexes as $name => $columns) {
                    $blueprint->index($columns, $name);
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            Schema::table($table, function (Blueprint $blueprint) use ($indexes) {
                foreach (array_keys($indexes) as $name) {
                    $blueprint->dropIndex($name);
                }
            });
        }
    }
};
