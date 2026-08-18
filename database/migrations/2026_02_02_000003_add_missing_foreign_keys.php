<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add missing foreign key constraints for data integrity.
     *
     * These FKs ensure referential integrity between related tables.
     */
    public function up(): void
    {
        // organization_licenses.organization_id -> organizations.id
        Schema::table('organization_licenses', function (Blueprint $table) {
            if (! $this->foreignKeyExists('organization_licenses', 'organization_licenses_organization_id_foreign')) {
                $table->foreign('organization_id', 'organization_licenses_organization_id_foreign')
                    ->references('id')
                    ->on('organizations')
                    ->cascadeOnDelete();
            }
        });

        // hash_chain_history.invoice_id -> invoices.id
        Schema::table('hash_chain_history', function (Blueprint $table) {
            if (! $this->foreignKeyExists('hash_chain_history', 'hash_chain_history_invoice_id_foreign')) {
                $table->foreign('invoice_id', 'hash_chain_history_invoice_id_foreign')
                    ->references('id')
                    ->on('invoices')
                    ->cascadeOnDelete();
            }
        });

        // licenses.issued_by -> users.id (nullable, so nullOnDelete)
        Schema::table('licenses', function (Blueprint $table) {
            if (Schema::hasColumn('licenses', 'issued_by')) {
                if (! $this->foreignKeyExists('licenses', 'licenses_issued_by_foreign')) {
                    $table->foreign('issued_by', 'licenses_issued_by_foreign')
                        ->references('id')
                        ->on('users')
                        ->nullOnDelete();
                }
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('licenses', function (Blueprint $table) {
            if ($this->foreignKeyExists('licenses', 'licenses_issued_by_foreign')) {
                $table->dropForeign('licenses_issued_by_foreign');
            }
        });

        Schema::table('hash_chain_history', function (Blueprint $table) {
            if ($this->foreignKeyExists('hash_chain_history', 'hash_chain_history_invoice_id_foreign')) {
                $table->dropForeign('hash_chain_history_invoice_id_foreign');
            }
        });

        Schema::table('organization_licenses', function (Blueprint $table) {
            if ($this->foreignKeyExists('organization_licenses', 'organization_licenses_organization_id_foreign')) {
                $table->dropForeign('organization_licenses_organization_id_foreign');
            }
        });
    }

    /**
     * Check if a foreign key exists on a table.
     */
    private function foreignKeyExists(string $table, string $foreignKeyName): bool
    {
        try {
            $foreignKeys = Schema::getForeignKeys($table);

            foreach ($foreignKeys as $fk) {
                if ($fk['name'] === $foreignKeyName) {
                    return true;
                }
            }
        } catch (Exception $e) {
            return false;
        }

        return false;
    }
};
