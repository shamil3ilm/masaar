<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Idempotency table prevents duplicate ZATCA submissions.
     * Essential for retry scenarios and queue-based processing.
     */
    public function up(): void
    {
        Schema::create('submission_idempotency', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('idempotency_key', 64)->unique();
            $table->foreignUuid('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();

            // Request fingerprint
            $table->string('request_hash', 64); // SHA-256 of request payload
            $table->string('endpoint');
            $table->string('method', 10);

            // Response storage for replay
            $table->enum('status', [
                'processing',  // Request in progress
                'completed',   // Successfully processed
                'failed',      // Failed (non-retryable)
                'expired',     // Idempotency window expired
            ])->default('processing');

            $table->integer('http_status_code')->nullable();
            $table->json('response_body')->nullable();
            $table->json('response_headers')->nullable();

            // ZATCA-specific tracking
            $table->string('zatca_request_id')->nullable();
            $table->string('zatca_clearance_status')->nullable();
            $table->text('zatca_errors')->nullable();

            // Retry tracking
            $table->integer('attempt_count')->default(1);
            $table->timestamp('first_attempt_at');
            $table->timestamp('last_attempt_at');
            $table->timestamp('completed_at')->nullable();

            // Expiry (idempotency window)
            $table->timestamp('expires_at');

            $table->timestamps();

            // Indexes
            $table->index(['invoice_id', 'status']);
            $table->index(['organization_id', 'created_at']);
            $table->index('expires_at');
        });

        // Submission state machine tracking
        Schema::create('invoice_submissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->uuid('idempotency_id')->nullable();

            // State machine
            $table->enum('state', [
                'draft',              // Not yet submitted
                'queued',             // In submission queue
                'pending_submission', // Being submitted
                'submitted',          // Sent to ZATCA, awaiting response
                'cleared',            // B2B: Cleared by ZATCA
                'reported',           // B2C: Reported to ZATCA
                'warning',            // Accepted with warnings
                'rejected',           // Rejected by ZATCA
                'failed',             // Technical failure
                'cancelled',          // Cancelled before submission
            ])->default('draft');

            $table->string('previous_state')->nullable();
            $table->timestamp('state_changed_at')->nullable();

            // ZATCA response
            $table->string('zatca_uuid')->nullable();
            $table->string('zatca_invoice_hash')->nullable();
            $table->string('clearance_status')->nullable();
            $table->string('reporting_status')->nullable();
            $table->json('zatca_warnings')->nullable();
            $table->json('zatca_errors')->nullable();

            // Submission metadata
            $table->enum('submission_type', ['clearance', 'reporting'])->nullable();
            $table->string('submission_mode')->default('sync'); // sync, async
            $table->string('queue_job_id')->nullable();

            // Retry configuration
            $table->integer('retry_count')->default(0);
            $table->integer('max_retries')->default(3);
            $table->timestamp('next_retry_at')->nullable();
            $table->string('last_error_code')->nullable();
            $table->text('last_error_message')->nullable();

            // Timing
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index(['invoice_id', 'state']);
            $table->index(['organization_id', 'state']);
            $table->index(['state', 'next_retry_at']);
            $table->index('zatca_uuid');

            // Foreign key
            $table->foreign('idempotency_id')
                ->references('id')
                ->on('submission_idempotency')
                ->nullOnDelete();
        });

        // State transition audit log
        Schema::create('submission_state_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('submission_id')->constrained('invoice_submissions')->cascadeOnDelete();

            $table->string('from_state')->nullable();
            $table->string('to_state');
            $table->string('trigger'); // api_call, queue_job, manual, timeout, retry
            $table->json('context')->nullable(); // Additional context data
            $table->text('notes')->nullable();

            $table->string('actor_type')->nullable(); // user, system, zatca
            $table->uuid('actor_id')->nullable();
            $table->ipAddress('ip_address')->nullable();

            $table->timestamps();

            $table->index(['submission_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('submission_state_logs');
        Schema::dropIfExists('invoice_submissions');
        Schema::dropIfExists('submission_idempotency');
    }
};
