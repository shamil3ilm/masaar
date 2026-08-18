<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add performance indexes for common query patterns.
     *
     * These indexes optimize:
     * - Dashboard aggregation queries
     * - Portal user activity lookups
     * - Admin filtering and reporting
     * - License and organization lookups
     */
    public function up(): void
    {
        // Organizations table - add vat_number if missing, then add indexes
        Schema::table('organizations', function (Blueprint $table) {
            // Add vat_number column if not exists (needed for ZATCA compliance)
            if (! Schema::hasColumn('organizations', 'vat_number')) {
                $table->string('vat_number', 15)->nullable()->after('name');
            }
        });

        Schema::table('organizations', function (Blueprint $table) {
            if (! $this->indexExists('organizations', 'organizations_status_index')) {
                $table->index('status', 'organizations_status_index');
            }
            if (! $this->indexExists('organizations', 'organizations_vat_number_index')) {
                $table->index('vat_number', 'organizations_vat_number_index');
            }
        });

        // Organization-User pivot - lookup by organization
        Schema::table('organization_user', function (Blueprint $table) {
            if (! $this->indexExists('organization_user', 'org_user_org_status_idx')) {
                $table->index(['organization_id', 'status'], 'org_user_org_status_idx');
            }
        });

        // Invoices table - date range and type filtering
        Schema::table('invoices', function (Blueprint $table) {
            if (! $this->indexExists('invoices', 'invoices_org_created_idx')) {
                $table->index(['organization_id', 'created_at'], 'invoices_org_created_idx');
            }
            if (! $this->indexExists('invoices', 'invoices_type_idx')) {
                $table->index('type', 'invoices_type_idx');
            }
            if (! $this->indexExists('invoices', 'invoices_issue_date_idx')) {
                $table->index('issue_date', 'invoices_issue_date_idx');
            }
        });

        // Invoice submissions - dashboard and reporting queries
        Schema::table('invoice_submissions', function (Blueprint $table) {
            // State-only index for aggregations
            if (! $this->indexExists('invoice_submissions', 'submissions_state_idx')) {
                $table->index('state', 'submissions_state_idx');
            }
            // State with created_at for time-series queries
            if (! $this->indexExists('invoice_submissions', 'submissions_state_created_idx')) {
                $table->index(['state', 'created_at'], 'submissions_state_created_idx');
            }
            // Organization date range queries (dashboard)
            if (! $this->indexExists('invoice_submissions', 'submissions_org_created_idx')) {
                $table->index(['organization_id', 'created_at'], 'submissions_org_created_idx');
            }
            // Submission type filtering
            if (! $this->indexExists('invoice_submissions', 'submissions_type_idx')) {
                $table->index('submission_type', 'submissions_type_idx');
            }
        });

        // Offline queue - processing and monitoring queries
        Schema::table('offline_queue', function (Blueprint $table) {
            // State with updated_at for failure monitoring
            if (! $this->indexExists('offline_queue', 'offline_queue_state_updated_idx')) {
                $table->index(['state', 'updated_at'], 'offline_queue_state_updated_idx');
            }
        });

        // Organization licenses - lookup by organization
        Schema::table('organization_licenses', function (Blueprint $table) {
            if (! $this->indexExists('organization_licenses', 'org_licenses_org_idx')) {
                $table->index('organization_id', 'org_licenses_org_idx');
            }
        });

        // License usage - monthly reporting
        Schema::table('license_usage', function (Blueprint $table) {
            if (! $this->indexExists('license_usage', 'license_usage_month_idx')) {
                $table->index('usage_month', 'license_usage_month_idx');
            }
        });

        // Certificate lineage - expiry alerts (uses valid_to column, not expires_at)
        // Note: Index on (valid_to, status) already exists from original migration
        // Adding single-column index for simpler expiry queries
        Schema::table('certificate_lineage', function (Blueprint $table) {
            if (! $this->indexExists('certificate_lineage', 'cert_lineage_valid_to_idx')) {
                $table->index('valid_to', 'cert_lineage_valid_to_idx');
            }
        });

        // Webhooks - organization filtering (already has org_id + is_active index)
        // Skip if already indexed via foreign key

        // API Keys table - expiry date index (is_active is already indexed with org_id)
        Schema::table('api_keys', function (Blueprint $table) {
            if (! $this->indexExists('api_keys', 'api_keys_expires_idx')) {
                $table->index('expires_at', 'api_keys_expires_idx');
            }
        });

        // Users table - add organization_id if needed for direct queries
        // Note: Primary relationship is via organization_user pivot table
        // but direct column can be useful for default org
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'default_organization_id')) {
                $table->uuid('default_organization_id')->nullable()->after('status');
                $table->index('default_organization_id', 'users_default_org_idx');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'default_organization_id')) {
                $table->dropIndex('users_default_org_idx');
                $table->dropColumn('default_organization_id');
            }
        });

        Schema::table('api_keys', function (Blueprint $table) {
            if ($this->indexExists('api_keys', 'api_keys_expires_idx')) {
                $table->dropIndex('api_keys_expires_idx');
            }
        });

        Schema::table('certificate_lineage', function (Blueprint $table) {
            if ($this->indexExists('certificate_lineage', 'cert_lineage_valid_to_idx')) {
                $table->dropIndex('cert_lineage_valid_to_idx');
            }
        });

        Schema::table('license_usage', function (Blueprint $table) {
            if ($this->indexExists('license_usage', 'license_usage_month_idx')) {
                $table->dropIndex('license_usage_month_idx');
            }
        });

        Schema::table('organization_licenses', function (Blueprint $table) {
            if ($this->indexExists('organization_licenses', 'org_licenses_org_idx')) {
                $table->dropIndex('org_licenses_org_idx');
            }
        });

        Schema::table('offline_queue', function (Blueprint $table) {
            if ($this->indexExists('offline_queue', 'offline_queue_state_updated_idx')) {
                $table->dropIndex('offline_queue_state_updated_idx');
            }
        });

        Schema::table('invoice_submissions', function (Blueprint $table) {
            if ($this->indexExists('invoice_submissions', 'submissions_state_idx')) {
                $table->dropIndex('submissions_state_idx');
            }
            if ($this->indexExists('invoice_submissions', 'submissions_state_created_idx')) {
                $table->dropIndex('submissions_state_created_idx');
            }
            if ($this->indexExists('invoice_submissions', 'submissions_org_created_idx')) {
                $table->dropIndex('submissions_org_created_idx');
            }
            if ($this->indexExists('invoice_submissions', 'submissions_type_idx')) {
                $table->dropIndex('submissions_type_idx');
            }
        });

        Schema::table('invoices', function (Blueprint $table) {
            if ($this->indexExists('invoices', 'invoices_org_created_idx')) {
                $table->dropIndex('invoices_org_created_idx');
            }
            if ($this->indexExists('invoices', 'invoices_type_idx')) {
                $table->dropIndex('invoices_type_idx');
            }
            if ($this->indexExists('invoices', 'invoices_issue_date_idx')) {
                $table->dropIndex('invoices_issue_date_idx');
            }
        });

        Schema::table('organization_user', function (Blueprint $table) {
            if ($this->indexExists('organization_user', 'org_user_org_status_idx')) {
                $table->dropIndex('org_user_org_status_idx');
            }
        });

        Schema::table('organizations', function (Blueprint $table) {
            if ($this->indexExists('organizations', 'organizations_status_index')) {
                $table->dropIndex('organizations_status_index');
            }
            if ($this->indexExists('organizations', 'organizations_vat_number_index')) {
                $table->dropIndex('organizations_vat_number_index');
            }
            // Don't drop vat_number column as it may have data
        });
    }

    /**
     * Check if an index exists on a table.
     */
    private function indexExists(string $table, string $indexName): bool
    {
        try {
            $indexes = Schema::getIndexes($table);

            foreach ($indexes as $index) {
                if ($index['name'] === $indexName) {
                    return true;
                }
            }
        } catch (Exception $e) {
            // Fallback for older Laravel versions
            return false;
        }

        return false;
    }
};
