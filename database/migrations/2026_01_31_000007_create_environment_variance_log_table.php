<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create environment variance log table.
 *
 * Tracks behavioral differences between ZATCA sandbox and production
 * environments for debugging and customer dispute resolution.
 *
 * Use cases:
 * - Invoice passes sandbox but fails production
 * - Different error codes for same issue
 * - Timing differences between environments
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('environment_variance_log')) {
            Schema::create('environment_variance_log', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('organization_id')->index();
                $table->uuid('invoice_id')->nullable()->index();

                // Variance classification
                $table->string('variance_type', 50);  // sandbox_only_pass, production_only_fail, etc.
                $table->string('rule_code', 50)->nullable();  // ZATCA error/rule code

                // Results from both environments
                $table->json('sandbox_result')->nullable();
                $table->json('production_result')->nullable();

                // Payload tracking
                $table->string('payload_hash', 64)->nullable();  // SHA-256 of request payload

                // ZATCA escalation
                $table->boolean('reported_to_zatca')->default(false);
                $table->string('zatca_ticket_id', 100)->nullable();

                // Notes and resolution
                $table->text('notes')->nullable();
                $table->string('resolution_status', 50)->default('open');  // open, resolved, wont_fix

                $table->timestamps();

                // Indexes for common queries
                $table->index(['variance_type', 'created_at']);
                $table->index(['organization_id', 'variance_type']);

                // Foreign keys
                $table->foreign('organization_id')
                    ->references('id')
                    ->on('organizations')
                    ->onDelete('cascade');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('environment_variance_log');
    }
};
