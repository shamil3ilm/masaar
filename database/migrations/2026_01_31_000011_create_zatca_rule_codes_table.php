<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create table for tracking ZATCA rule codes.
     *
     * ZATCA Behavior Drift Mitigation:
     * ZATCA occasionally changes business-rule enforcement silently.
     * This table tracks all rule codes seen and flags new ones for review.
     *
     * When a new rule code appears (one we haven't seen before), it's flagged
     * as needs_review to alert operators about potential ZATCA behavior changes.
     */
    public function up(): void
    {
        Schema::create('zatca_rule_codes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 64)->unique(); // The rule code (e.g., BR-KSA-23)

            // Discovery tracking
            $table->timestamp('first_seen_at');
            $table->boolean('needs_review')->default(true);

            // Review tracking
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();

            // Optional: categorization
            $table->string('category')->nullable(); // validation, business_rule, etc.
            $table->string('severity')->nullable(); // error, warning, info
            $table->text('description')->nullable();

            // Source tracking
            $table->string('first_seen_environment')->nullable(); // sandbox, production
            $table->foreignUuid('first_seen_organization_id')
                ->nullable()
                ->constrained('organizations')
                ->nullOnDelete();

            $table->timestamps();

            // Index for review queries
            $table->index(['needs_review', 'first_seen_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('zatca_rule_codes');
    }
};
