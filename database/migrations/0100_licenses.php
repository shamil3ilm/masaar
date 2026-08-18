<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('licenses', function (Blueprint $table) {
            $table->uuid('id');
            $table->uuid('org_id')->nullable();
            $table->string('api_key', 64);
            $table->string('api_secret_hash', 255);
            $table->string('environment', 20)->default('sandbox');
            $table->string('organization_name', 255);
            $table->string('organization_vat', 15)->nullable();
            $table->string('contact_email', 255);
            $table->string('contact_phone', 255)->nullable();
            $table->string('tier', 255)->default('starter');
            $table->unsignedInteger('invoices_per_month')->default(100);
            $table->unsignedInteger('max_organizations')->default(1);
            $table->unsignedInteger('calls_per_min')->default(60);
            $table->unsignedInteger('calls_per_day')->default(10000);
            $table->json('features')->nullable();
            $table->json('scopes')->nullable();
            $table->boolean('offline_mode')->default(false);
            $table->boolean('multi_tenant')->default(false);
            $table->boolean('webhook_enabled')->default(true);
            $table->string('status', 255)->default('active');
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->string('suspension_reason', 255)->nullable();
            $table->uuid('issued_by')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->primary(['id']);
            $table->unique(['api_key'], 'licenses_api_key_unique');
            $table->index(['environment'], 'licenses_environment_index');
            $table->index(['expires_at'], 'licenses_expires_at_index');
            $table->index(['issued_by'], 'licenses_issued_by_foreign');
            $table->index(['org_id'], 'licenses_organization_id_foreign');
            $table->index(['organization_vat'], 'licenses_organization_vat_index');
            $table->index(['status'], 'licenses_status_index');
            $table->index(['tier'], 'licenses_tier_index');
            $table->foreign('issued_by', 'licenses_issued_by_foreign')->references('id')->on('users')->nullOnDelete();
            $table->foreign('org_id', 'licenses_organization_id_foreign')->references('id')->on('organizations')->nullOnDelete();
        });

        Schema::create('license_usage', function (Blueprint $table) {
            $table->uuid('id');
            $table->uuid('license_id');
            $table->date('usage_date');
            $table->string('usage_month', 7);
            $table->unsignedInteger('invoices_submitted')->default(0);
            $table->unsignedInteger('invoices_cleared')->default(0);
            $table->unsignedInteger('invoices_reported')->default(0);
            $table->unsignedInteger('invoices_failed')->default(0);
            $table->unsignedInteger('api_calls')->default(0);
            $table->unsignedInteger('api_errors')->default(0);
            $table->unsignedInteger('orgs_active')->default(0);
            $table->unsignedInteger('users_active')->default(0);
            $table->decimal('total_value', 18, 2)->default(0.00);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->primary(['id']);
            $table->unique(['license_id', 'usage_date'], 'license_usage_license_id_usage_date_unique');
            $table->index(['license_id', 'usage_month'], 'license_usage_license_id_usage_month_index');
            $table->index(['usage_month'], 'license_usage_month_idx');
            $table->foreign('license_id', 'license_usage_license_id_foreign')->references('id')->on('licenses')->cascadeOnDelete();
        });

        Schema::create('license_rate_limits', function (Blueprint $table) {
            $table->uuid('id');
            $table->uuid('license_id');
            $table->string('window_type', 255);
            $table->string('window_key', 255);
            $table->unsignedInteger('request_count')->default(0);
            $table->timestamp('window_start');
            $table->timestamp('window_expires');
            $table->primary(['id']);
            $table->unique(['license_id', 'window_type', 'window_key'], 'license_rate_limits_license_id_window_type_window_key_unique');
            $table->index(['window_expires'], 'license_rate_limits_window_expires_index');
            $table->foreign('license_id', 'license_rate_limits_license_id_foreign')->references('id')->on('licenses')->cascadeOnDelete();
        });

        Schema::create('license_audit_logs', function (Blueprint $table) {
            $table->uuid('id');
            $table->uuid('license_id');
            $table->string('event', 255);
            $table->string('actor_type', 255)->nullable();
            $table->uuid('actor_id')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->text('reason')->nullable();
            $table->timestamp('created_at');
            $table->primary(['id']);
            $table->index(['event'], 'license_audit_logs_event_index');
            $table->index(['license_id', 'created_at'], 'license_audit_logs_license_id_created_at_index');
            $table->foreign('license_id', 'license_audit_logs_license_id_foreign')->references('id')->on('licenses')->cascadeOnDelete();
        });

        Schema::create('organization_licenses', function (Blueprint $table) {
            $table->uuid('id');
            $table->uuid('org_id');
            $table->uuid('license_id');
            $table->timestamp('linked_at');
            $table->uuid('linked_by')->nullable();
            $table->primary(['id']);
            $table->index(['org_id'], 'org_licenses_org_idx');
            $table->index(['license_id'], 'organization_licenses_license_id_foreign');
            $table->unique(['org_id', 'license_id'], 'organization_licenses_organization_id_license_id_unique');
            $table->foreign('license_id', 'organization_licenses_license_id_foreign')->references('id')->on('licenses')->cascadeOnDelete();
            $table->foreign('org_id', 'organization_licenses_organization_id_foreign')->references('id')->on('organizations')->cascadeOnDelete();
        });

        // Scopes are defined in App\Domains\Licensing\Enums\ApiScope, not in a
        // table, so there is one list rather than two to keep in step.

        Schema::create('usage_events', function (Blueprint $table) {
            $table->uuid('id');
            $table->uuid('license_id');
            $table->uuid('org_id')->nullable();
            $table->uuid('api_key_id')->nullable();
            $table->string('event', 50);
            $table->string('event_category', 30)->default('api');
            $table->unsignedInteger('quantity')->default(1);
            $table->boolean('billable')->default(true);
            $table->string('request_id', 64)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->uuid('resource_id')->nullable();
            $table->string('resource_type', 50)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at');
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('status', 20)->default('success');
            $table->string('error_code', 50)->nullable();
            $table->primary(['id']);
            $table->index(['billable'], 'usage_events_billable_index');
            $table->index(['event', 'occurred_at'], 'usage_events_event_occurred_at_index');
            $table->index(['license_id', 'event', 'occurred_at'], 'usage_events_license_id_event_occurred_at_index');
            $table->index(['license_id', 'occurred_at'], 'usage_events_license_id_occurred_at_index');
            $table->index(['org_id', 'occurred_at'], 'usage_events_organization_id_occurred_at_index');
            $table->index(['request_id'], 'usage_events_request_id_index');
            $table->foreign('license_id', 'usage_events_license_id_foreign')->references('id')->on('licenses')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_events');
        Schema::dropIfExists('organization_licenses');
        Schema::dropIfExists('license_audit_logs');
        Schema::dropIfExists('license_rate_limits');
        Schema::dropIfExists('license_usage');
        Schema::dropIfExists('licenses');
    }
};
