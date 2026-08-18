<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add signing time tracking for audit provability.
     *
     * ZATCA Compliance Note:
     * In disputes, auditors may ask: "Was the invoice issued before or after
     * the signing authority timestamp?" This migration adds explicit tracking
     * of the authoritative signing time separate from the business issue date.
     *
     * - signed_at: When XAdES signature was applied (authoritative time)
     * - clearance_state: ZATCA clearance state tracking
     * - clearance_confirmed_at: When clearance was confirmed
     * - clearance_check_count: Number of status checks performed
     */
    public function up(): void
    {
        Schema::table('invoice_submissions', function (Blueprint $table) {
            // Authoritative signing time (when XAdES signature was applied)
            // This is the cryptographic proof timestamp, separate from issue_date
            if (! Schema::hasColumn('invoice_submissions', 'signed_at')) {
                $table->timestamp('signed_at')->nullable()->after('queued_at');
            }

            // Clearance state tracking for B2B invoices
            if (! Schema::hasColumn('invoice_submissions', 'clearance_state')) {
                $table->string('clearance_state')->nullable()->after('clearance_status');
            }
            if (! Schema::hasColumn('invoice_submissions', 'clearance_confirmed_at')) {
                $table->timestamp('clearance_confirmed_at')->nullable()->after('clearance_state');
            }
            if (! Schema::hasColumn('invoice_submissions', 'clearance_check_count')) {
                $table->integer('clearance_check_count')->default(0)->after('clearance_confirmed_at');
            }
        });

        // Add index for time-based queries (if column exists and index doesn't)
        if (Schema::hasColumn('invoice_submissions', 'signed_at')) {
            $indexExists = collect(Schema::getIndexes('invoice_submissions'))
                ->pluck('name')
                ->contains('invoice_submissions_signed_at_index');

            if (! $indexExists) {
                Schema::table('invoice_submissions', function (Blueprint $table) {
                    $table->index('signed_at');
                });
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoice_submissions', function (Blueprint $table) {
            // Drop index first if it exists
            $indexExists = collect(Schema::getIndexes('invoice_submissions'))
                ->pluck('name')
                ->contains('invoice_submissions_signed_at_index');

            if ($indexExists) {
                $table->dropIndex(['signed_at']);
            }

            // Drop columns if they exist
            $columnsToDrop = [];
            foreach (['signed_at', 'clearance_state', 'clearance_confirmed_at', 'clearance_check_count'] as $column) {
                if (Schema::hasColumn('invoice_submissions', $column)) {
                    $columnsToDrop[] = $column;
                }
            }

            if (! empty($columnsToDrop)) {
                $table->dropColumn($columnsToDrop);
            }
        });
    }
};
