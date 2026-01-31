<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create license and usage tracking tables.
 *
 * Implements API Key + Usage Metering for CompliPay licensing.
 */
return new class extends Migration
{
    public function up(): void
    {
        // API Keys / Licenses table
        Schema::create('licenses', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // License identification
            $table->string('api_key', 64)->unique();
            $table->string('api_secret_hash', 255); // Hashed secret

            // Owner information
            $table->string('organization_name');
            $table->string('organization_vat', 15)->nullable(); // Saudi VAT number
            $table->string('contact_email');
            $table->string('contact_phone')->nullable();

            // License tier and limits
            $table->string('tier')->default('starter'); // starter, professional, enterprise, unlimited
            $table->unsignedInteger('max_invoices_per_month')->default(100);
            $table->unsignedInteger('max_organizations')->default(1);
            $table->unsignedInteger('max_api_calls_per_minute')->default(60);
            $table->unsignedInteger('max_api_calls_per_day')->default(10000);

            // Features
            $table->json('features')->nullable(); // Feature flags for this license
            $table->boolean('offline_mode_enabled')->default(false);
            $table->boolean('multi_tenant_enabled')->default(false);
            $table->boolean('webhook_enabled')->default(true);

            // Status
            $table->string('status')->default('active'); // active, suspended, expired, revoked
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->string('suspension_reason')->nullable();

            // Issued by
            $table->uuid('issued_by')->nullable(); // Admin user ID
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index('status');
            $table->index('tier');
            $table->index('organization_vat');
            $table->index('expires_at');
        });

        // Usage tracking table (aggregated daily)
        Schema::create('license_usage', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('license_id');

            // Period
            $table->date('usage_date');
            $table->string('usage_month', 7); // YYYY-MM for monthly aggregation

            // Usage counters
            $table->unsignedInteger('invoices_submitted')->default(0);
            $table->unsignedInteger('invoices_cleared')->default(0);
            $table->unsignedInteger('invoices_reported')->default(0);
            $table->unsignedInteger('invoices_failed')->default(0);

            $table->unsignedInteger('api_calls')->default(0);
            $table->unsignedInteger('api_errors')->default(0);

            $table->unsignedInteger('organizations_active')->default(0);
            $table->unsignedInteger('users_active')->default(0);

            // Billing metrics
            $table->decimal('invoice_total_value', 18, 2)->default(0); // Total SAR value processed

            $table->timestamps();

            // Unique constraint for one record per license per day
            $table->unique(['license_id', 'usage_date']);
            $table->index(['license_id', 'usage_month']);

            $table->foreign('license_id')
                ->references('id')
                ->on('licenses')
                ->onDelete('cascade');
        });

        // Real-time rate limiting (Redis-backed, but with DB fallback)
        Schema::create('license_rate_limits', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('license_id');

            $table->string('window_type'); // minute, hour, day
            $table->string('window_key'); // YYYY-MM-DD-HH-mm or similar
            $table->unsignedInteger('request_count')->default(0);

            $table->timestamp('window_start');
            $table->timestamp('window_expires');

            $table->unique(['license_id', 'window_type', 'window_key']);
            $table->index('window_expires'); // For cleanup

            $table->foreign('license_id')
                ->references('id')
                ->on('licenses')
                ->onDelete('cascade');
        });

        // License audit log
        Schema::create('license_audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('license_id');

            $table->string('event'); // created, activated, suspended, renewed, upgraded, etc.
            $table->string('actor_type')->nullable(); // user, system, admin
            $table->uuid('actor_id')->nullable();
            $table->string('ip_address', 45)->nullable();

            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->text('reason')->nullable();

            $table->timestamp('created_at');

            $table->index(['license_id', 'created_at']);
            $table->index('event');

            $table->foreign('license_id')
                ->references('id')
                ->on('licenses')
                ->onDelete('cascade');
        });

        // Organization to License mapping (for multi-tenant)
        Schema::create('organization_licenses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('license_id');

            $table->timestamp('linked_at');
            $table->uuid('linked_by')->nullable();

            $table->unique(['organization_id', 'license_id']);

            $table->foreign('license_id')
                ->references('id')
                ->on('licenses')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_licenses');
        Schema::dropIfExists('license_audit_logs');
        Schema::dropIfExists('license_rate_limits');
        Schema::dropIfExists('license_usage');
        Schema::dropIfExists('licenses');
    }
};
