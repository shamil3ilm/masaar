<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Offline queue for ZATCA submissions during network issues
     * or ZATCA unavailability. Supports POS and retail scenarios.
     */
    public function up(): void
    {
        Schema::create('offline_queue', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();

            // Invoice data snapshot (for offline resubmission)
            $table->longText('signed_xml');
            $table->string('invoice_hash', 64);
            $table->text('qr_code');

            // Queue state
            $table->enum('state', [
                'pending',      // Waiting to be processed
                'processing',   // Currently being submitted
                'completed',    // Successfully submitted
                'failed',       // Permanently failed
                'cancelled',    // Manually cancelled
            ])->default('pending');

            $table->enum('priority', ['low', 'normal', 'high', 'critical'])->default('normal');

            // Retry tracking
            $table->integer('attempts')->default(0);
            $table->integer('max_attempts')->default(3);
            $table->timestamp('next_attempt_at')->nullable();
            $table->text('last_error')->nullable();

            // ZATCA response (when completed)
            $table->json('zatca_response')->nullable();

            // Timestamps
            $table->timestamp('queued_at');
            $table->timestamp('processing_started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason')->nullable();

            $table->timestamps();

            // Indexes
            $table->index(['organization_id', 'state']);
            $table->index(['state', 'next_attempt_at']);
            $table->index(['state', 'priority', 'queued_at']);
            $table->index('invoice_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('offline_queue');
    }
};
