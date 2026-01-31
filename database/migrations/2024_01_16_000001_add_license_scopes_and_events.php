<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add scopes, environment, and event-based usage tracking.
 *
 * This migration enhances the licensing system for:
 * - Fine-grained scope-based authorization
 * - Explicit environment separation (sandbox/production)
 * - Append-only usage events for billing and audit
 */
return new class extends Migration
{
    public function up(): void
    {
        // Add scopes and environment to licenses table
        Schema::table('licenses', function (Blueprint $table) {
            // Scopes for fine-grained authorization
            $table->json('scopes')->nullable()->after('features');

            // Explicit environment (sandbox/production)
            $table->string('environment', 20)->default('sandbox')->after('api_secret_hash');

            // Index for environment filtering
            $table->index('environment');
        });

        // Create append-only usage events table
        // This table is NEVER deleted from - it serves as billing and audit evidence
        Schema::create('usage_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('license_id');
            $table->uuid('organization_id')->nullable();
            $table->uuid('api_key_id')->nullable(); // For tracking which key was used

            // Event classification
            $table->string('event', 50); // invoice.submit, invoice.clearance, etc.
            $table->string('event_category', 30)->default('api'); // api, invoice, webhook, etc.

            // Quantity (usually 1, but can be batch size)
            $table->unsignedInteger('quantity')->default(1);

            // Billable flag (some events may not be billable)
            $table->boolean('billable')->default(true);

            // Request context
            $table->string('request_id', 64)->nullable(); // For correlation
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();

            // Resource references (for audit trail)
            $table->uuid('resource_id')->nullable(); // e.g., invoice_id
            $table->string('resource_type', 50)->nullable(); // e.g., 'invoice', 'organization'

            // Additional metadata (flexible for different event types)
            $table->json('metadata')->nullable();

            // Timing
            $table->timestamp('occurred_at');
            $table->unsignedInteger('duration_ms')->nullable(); // Request duration

            // Response context
            $table->string('status', 20)->default('success'); // success, failed, error
            $table->string('error_code', 50)->nullable();

            // Indexes for querying
            $table->index(['license_id', 'occurred_at']);
            $table->index(['license_id', 'event', 'occurred_at']);
            $table->index(['organization_id', 'occurred_at']);
            $table->index(['event', 'occurred_at']);
            $table->index('request_id');
            $table->index('billable');

            // Foreign key (but no cascade delete - we never delete usage events)
            $table->foreign('license_id')
                ->references('id')
                ->on('licenses')
                ->onDelete('restrict'); // Prevent deletion of licenses with usage

            // Partitioning hint (for future optimization)
            // Events can be partitioned by month: occurred_at
        });

        // Create API key scopes reference table
        Schema::create('license_scope_definitions', function (Blueprint $table) {
            $table->string('scope', 50)->primary();
            $table->string('description', 255);
            $table->string('category', 30); // invoice, organization, compliance, admin
            $table->boolean('requires_production')->default(false); // Some scopes only work in production
            $table->json('implied_scopes')->nullable(); // Scopes that are automatically granted
            $table->timestamps();
        });

        // Seed default scopes
        $this->seedDefaultScopes();
    }

    public function down(): void
    {
        Schema::dropIfExists('license_scope_definitions');
        Schema::dropIfExists('usage_events');

        Schema::table('licenses', function (Blueprint $table) {
            $table->dropIndex(['environment']);
            $table->dropColumn(['scopes', 'environment']);
        });
    }

    /**
     * Seed default scope definitions.
     */
    private function seedDefaultScopes(): void
    {
        $scopes = [
            // Invoice operations
            [
                'scope' => 'invoice.submit',
                'description' => 'Submit invoices for clearance/reporting',
                'category' => 'invoice',
                'requires_production' => false,
                'implied_scopes' => null,
            ],
            [
                'scope' => 'invoice.read',
                'description' => 'Read invoice details and status',
                'category' => 'invoice',
                'requires_production' => false,
                'implied_scopes' => null,
            ],
            [
                'scope' => 'invoice.cancel',
                'description' => 'Cancel/void invoices (credit notes)',
                'category' => 'invoice',
                'requires_production' => false,
                'implied_scopes' => json_encode(['invoice.read']),
            ],
            [
                'scope' => 'invoice.batch',
                'description' => 'Submit batch invoices',
                'category' => 'invoice',
                'requires_production' => false,
                'implied_scopes' => json_encode(['invoice.submit']),
            ],

            // Compliance operations
            [
                'scope' => 'compliance.status',
                'description' => 'Check ZATCA compliance status',
                'category' => 'compliance',
                'requires_production' => false,
                'implied_scopes' => null,
            ],
            [
                'scope' => 'compliance.certificate',
                'description' => 'Manage ZATCA certificates',
                'category' => 'compliance',
                'requires_production' => false,
                'implied_scopes' => null,
            ],
            [
                'scope' => 'compliance.onboarding',
                'description' => 'Complete ZATCA onboarding',
                'category' => 'compliance',
                'requires_production' => false,
                'implied_scopes' => json_encode(['compliance.certificate']),
            ],

            // Organization operations
            [
                'scope' => 'organization.read',
                'description' => 'Read organization details',
                'category' => 'organization',
                'requires_production' => false,
                'implied_scopes' => null,
            ],
            [
                'scope' => 'organization.write',
                'description' => 'Create/update organizations',
                'category' => 'organization',
                'requires_production' => false,
                'implied_scopes' => json_encode(['organization.read']),
            ],

            // Webhook operations
            [
                'scope' => 'webhook.manage',
                'description' => 'Manage webhook endpoints',
                'category' => 'webhook',
                'requires_production' => false,
                'implied_scopes' => null,
            ],

            // Reporting
            [
                'scope' => 'reports.read',
                'description' => 'Access usage and compliance reports',
                'category' => 'reports',
                'requires_production' => false,
                'implied_scopes' => null,
            ],
            [
                'scope' => 'reports.export',
                'description' => 'Export reports in various formats',
                'category' => 'reports',
                'requires_production' => false,
                'implied_scopes' => json_encode(['reports.read']),
            ],
        ];

        foreach ($scopes as $scope) {
            \Illuminate\Support\Facades\DB::table('license_scope_definitions')->insert([
                ...$scope,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
};
