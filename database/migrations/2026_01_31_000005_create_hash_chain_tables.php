<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Hash chain integrity tables.
     *
     * These tables ensure:
     * - Single-writer per sequence (via locks)
     * - Atomic hash persistence
     * - Certificate lineage tracking
     * - Audit query capability in <10 minutes
     */
    public function up(): void
    {
        // Current state - one row per organization
        Schema::create('hash_chain_state', function (Blueprint $table) {
            $table->uuid('organization_id')->primary();
            $table->string('last_hash', 64);           // SHA256 base64
            $table->unsignedBigInteger('last_icv');
            $table->uuid('last_invoice_id');
            $table->string('certificate_id', 64);      // Certificate fingerprint
            $table->json('certificate_transition')->nullable(); // If cert changed
            $table->timestamp('updated_at');

            $table->foreign('organization_id')
                ->references('id')
                ->on('organizations')
                ->cascadeOnDelete();
        });

        // Full history - for audit and verification
        Schema::create('hash_chain_history', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->uuid('invoice_id');
            $table->string('invoice_hash', 64);
            $table->string('previous_hash', 64);
            $table->unsignedBigInteger('icv');
            $table->string('certificate_id', 64);
            $table->json('certificate_transition')->nullable();
            $table->timestamp('created_at');

            // Indexes for fast audit queries
            $table->index(['organization_id', 'icv']);
            $table->index(['organization_id', 'certificate_id']);
            $table->index(['certificate_id', 'created_at']); // "All invoices signed with cert X"
            $table->index('invoice_id');
        });

        // Certificate lineage - track all certificates used
        Schema::create('certificate_lineage', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('certificate_id', 64)->unique();
            $table->string('certificate_serial');
            $table->string('issuer');
            $table->timestamp('valid_from');
            $table->timestamp('valid_to');
            $table->unsignedBigInteger('first_icv')->nullable();  // First invoice signed
            $table->unsignedBigInteger('last_icv')->nullable();   // Last invoice signed
            $table->enum('status', ['active', 'expired', 'revoked', 'superseded']);
            $table->uuid('superseded_by')->nullable();            // Next certificate
            $table->text('transition_reason')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index(['valid_to', 'status']); // For expiry queries
        });

        // Add certificate_id to invoices for direct querying
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('signing_certificate_id', 64)->nullable()->after('signed_xml');
            $table->string('rule_version', 20)->nullable()->after('signing_certificate_id');
            $table->string('schema_version', 20)->nullable()->after('rule_version');

            $table->index('signing_certificate_id');
        });

        // Add to invoice_submissions for partial success tracking
        Schema::table('invoice_submissions', function (Blueprint $table) {
            $table->enum('clearance_state', [
                'unknown',           // Initial state
                'pending_clearance', // Submitted, awaiting ZATCA
                'conditionally_accepted', // 200 but not final
                'cleared',           // Terminal: success
                'reported',          // Terminal: success (B2C)
                'rejected',          // Terminal: failure
                'timeout',           // Need to re-check
            ])->default('unknown')->after('reporting_status');

            $table->timestamp('clearance_confirmed_at')->nullable()->after('clearance_state');
            $table->integer('clearance_check_count')->default(0)->after('clearance_confirmed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoice_submissions', function (Blueprint $table) {
            $table->dropColumn(['clearance_state', 'clearance_confirmed_at', 'clearance_check_count']);
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['signing_certificate_id', 'rule_version', 'schema_version']);
        });

        Schema::dropIfExists('certificate_lineage');
        Schema::dropIfExists('hash_chain_history');
        Schema::dropIfExists('hash_chain_state');
    }
};
