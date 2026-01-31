<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add compliance metadata fields and critical constraints.
 *
 * This migration adds:
 * - Algorithm versioning fields for cryptographic obsolescence protection
 * - Belt+suspenders ICV constraint (DB-level protection against split-brain)
 * - Legal hold support fields
 */
return new class extends Migration
{
    public function up(): void
    {
        // Add algorithm versioning to invoices (if not already present)
        Schema::table('invoices', function (Blueprint $table) {
            // Compliance metadata - already added in previous migration
            // This ensures they exist with proper defaults

            // Add compliance timestamp if not exists
            if (!Schema::hasColumn('invoices', 'compliance_determined_at')) {
                $table->timestamp('compliance_determined_at')->nullable()->after('schema_version');
            }

            // Add signature algorithm tracking
            if (!Schema::hasColumn('invoices', 'signature_algorithm')) {
                $table->string('signature_algorithm', 50)->nullable()->after('compliance_determined_at');
            }

            // Add hash algorithm tracking
            if (!Schema::hasColumn('invoices', 'hash_algorithm')) {
                $table->string('hash_algorithm', 20)->nullable()->after('signature_algorithm');
            }
        });

        // BELT+SUSPENDERS: Ensure unique ICV constraint exists
        // This is a DB-level protection against split-brain scenarios where
        // multiple workers might try to use the same ICV
        $this->ensureIcvConstraint();

        // Add legal hold tracking to organizations
        Schema::table('organizations', function (Blueprint $table) {
            if (!Schema::hasColumn('organizations', 'legal_hold_reference')) {
                $table->string('legal_hold_reference')->nullable()->after('compliance_profile');
            }
            if (!Schema::hasColumn('organizations', 'legal_hold_at')) {
                $table->timestamp('legal_hold_at')->nullable()->after('legal_hold_reference');
            }
            if (!Schema::hasColumn('organizations', 'legal_hold_expires_at')) {
                $table->timestamp('legal_hold_expires_at')->nullable()->after('legal_hold_at');
            }
        });

        // Add index for legal hold queries
        $this->addIndexIfNotExists('organizations', 'legal_hold_reference', 'organizations_legal_hold_idx');
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $columns = [];
            if (Schema::hasColumn('invoices', 'compliance_determined_at')) {
                $columns[] = 'compliance_determined_at';
            }
            if (Schema::hasColumn('invoices', 'signature_algorithm')) {
                $columns[] = 'signature_algorithm';
            }
            if (Schema::hasColumn('invoices', 'hash_algorithm')) {
                $columns[] = 'hash_algorithm';
            }
            if (!empty($columns)) {
                $table->dropColumn($columns);
            }
        });

        Schema::table('organizations', function (Blueprint $table) {
            $columns = [];
            if (Schema::hasColumn('organizations', 'legal_hold_reference')) {
                $columns[] = 'legal_hold_reference';
            }
            if (Schema::hasColumn('organizations', 'legal_hold_at')) {
                $columns[] = 'legal_hold_at';
            }
            if (Schema::hasColumn('organizations', 'legal_hold_expires_at')) {
                $columns[] = 'legal_hold_expires_at';
            }
            if (!empty($columns)) {
                $table->dropColumn($columns);
            }
        });
    }

    /**
     * Ensure the ICV unique constraint exists.
     * This is critical for preventing split-brain ICV duplication.
     */
    private function ensureIcvConstraint(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            $indexExists = collect(
                DB::select("SHOW INDEX FROM invoices WHERE Key_name = 'invoices_org_icv_unique'")
            )->isNotEmpty();

            if (!$indexExists && Schema::hasColumn('invoices', 'icv')) {
                // MySQL: Add unique index
                DB::statement('CREATE UNIQUE INDEX invoices_org_icv_unique ON invoices (organization_id, icv)');
            }
        } elseif ($driver === 'pgsql') {
            $indexExists = DB::selectOne(
                "SELECT 1 FROM pg_indexes WHERE indexname = 'invoices_org_icv_unique'"
            );

            if (!$indexExists && Schema::hasColumn('invoices', 'icv')) {
                // PostgreSQL: Add unique index
                DB::statement('CREATE UNIQUE INDEX invoices_org_icv_unique ON invoices (organization_id, icv)');
            }
        } elseif ($driver === 'sqlite') {
            // SQLite: Check and add via pragma
            if (Schema::hasColumn('invoices', 'icv')) {
                try {
                    DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS invoices_org_icv_unique ON invoices (organization_id, icv)');
                } catch (\Exception $e) {
                    // Index might already exist
                }
            }
        }
    }

    /**
     * Add index if it doesn't exist.
     */
    private function addIndexIfNotExists(string $table, string $column, string $indexName): void
    {
        $driver = Schema::getConnection()->getDriverName();

        try {
            if ($driver === 'mysql') {
                $indexExists = collect(
                    DB::select("SHOW INDEX FROM {$table} WHERE Key_name = '{$indexName}'")
                )->isNotEmpty();

                if (!$indexExists) {
                    DB::statement("CREATE INDEX {$indexName} ON {$table} ({$column})");
                }
            } elseif ($driver === 'pgsql') {
                $indexExists = DB::selectOne(
                    "SELECT 1 FROM pg_indexes WHERE indexname = '{$indexName}'"
                );

                if (!$indexExists) {
                    DB::statement("CREATE INDEX {$indexName} ON {$table} ({$column})");
                }
            } elseif ($driver === 'sqlite') {
                DB::statement("CREATE INDEX IF NOT EXISTS {$indexName} ON {$table} ({$column})");
            }
        } catch (\Exception $e) {
            // Index might already exist, ignore
        }
    }
};
