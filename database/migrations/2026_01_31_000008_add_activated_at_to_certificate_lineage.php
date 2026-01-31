<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add activated_at column for certificate overlap resolution.
 *
 * Policy: When multiple certificates are valid simultaneously,
 * prefer the one with the most recent activated_at timestamp.
 * This handles same-second edge cases deterministically.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('certificate_lineage', function (Blueprint $table) {
            if (!Schema::hasColumn('certificate_lineage', 'activated_at')) {
                $table->timestamp('activated_at')->nullable()->after('valid_to');
                $table->index(['organization_id', 'status', 'activated_at']);
            }

            // Add revoked_at for key compromise tracking
            if (!Schema::hasColumn('certificate_lineage', 'revoked_at')) {
                $table->timestamp('revoked_at')->nullable()->after('activated_at');
            }

            // Add certificate_hash for SHA-256 fingerprint
            if (!Schema::hasColumn('certificate_lineage', 'certificate_hash')) {
                $table->string('certificate_hash', 64)->nullable()->after('certificate_serial');
            }
        });

        // Backfill activated_at from created_at for existing records
        DB::table('certificate_lineage')
            ->whereNull('activated_at')
            ->update(['activated_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('certificate_lineage', function (Blueprint $table) {
            $columns = [];
            if (Schema::hasColumn('certificate_lineage', 'activated_at')) {
                $columns[] = 'activated_at';
            }
            if (Schema::hasColumn('certificate_lineage', 'revoked_at')) {
                $columns[] = 'revoked_at';
            }
            if (Schema::hasColumn('certificate_lineage', 'certificate_hash')) {
                $columns[] = 'certificate_hash';
            }
            if (!empty($columns)) {
                $table->dropColumn($columns);
            }
        });
    }
};
