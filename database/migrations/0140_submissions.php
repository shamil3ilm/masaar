<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('submission_idempotency', function (Blueprint $table) {
            $table->uuid('id');
            $table->string('idempotency_key', 64);
            $table->uuid('invoice_id');
            $table->uuid('org_id');
            $table->string('request_hash', 64);
            $table->string('endpoint', 255);
            $table->string('method', 10);
            $table->enum('status', ['processing', 'completed', 'failed', 'expired'])->default('processing');
            $table->integer('http_status_code')->nullable();
            $table->json('response_body')->nullable();
            $table->json('response_headers')->nullable();
            $table->string('zatca_request_id', 255)->nullable();
            $table->string('clearance_status', 255)->nullable();
            $table->text('zatca_errors')->nullable();
            $table->integer('attempt_count')->default(1);
            $table->timestamp('first_attempt_at');
            $table->timestamp('last_attempt_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->primary(['id']);
            $table->index(['expires_at'], 'submission_idempotency_expires_at_index');
            $table->unique(['idempotency_key'], 'submission_idempotency_idempotency_key_unique');
            $table->index(['invoice_id', 'status'], 'submission_idempotency_invoice_id_status_index');
            $table->index(['org_id', 'created_at'], 'submission_idempotency_organization_id_created_at_index');
            $table->foreign('invoice_id', 'submission_idempotency_invoice_id_foreign')->references('id')->on('invoices')->cascadeOnDelete();
            $table->foreign('org_id', 'submission_idempotency_organization_id_foreign')->references('id')->on('organizations')->cascadeOnDelete();
        });

        Schema::create('invoice_submissions', function (Blueprint $table) {
            $table->uuid('id');
            $table->uuid('invoice_id');
            $table->uuid('org_id');
            $table->uuid('created_by')->nullable();
            $table->uuid('idempotency_id')->nullable();
            $table->enum('state', ['draft', 'queued', 'pending_submission', 'submitted', 'cleared', 'reported', 'warning', 'rejected', 'failed', 'cancelled'])->default('draft');
            $table->string('previous_state', 255)->nullable();
            $table->timestamp('state_changed_at')->nullable();
            $table->string('zatca_uuid', 255)->nullable();
            $table->string('invoice_hash', 255)->nullable();
            $table->string('clearance_status', 255)->nullable();
            $table->string('reporting_status', 255)->nullable();
            $table->enum('clearance_state', ['unknown', 'pending_clearance', 'conditionally_accepted', 'cleared', 'reported', 'rejected', 'timeout'])->default('unknown');
            $table->timestamp('cleared_at')->nullable();
            $table->integer('check_count')->default(0);
            $table->json('zatca_warnings')->nullable();
            $table->json('zatca_errors')->nullable();
            $table->enum('submission_type', ['clearance', 'reporting'])->nullable();
            $table->string('submission_mode', 255)->default('sync');
            $table->string('queue_job_id', 255)->nullable();
            $table->integer('retry_count')->default(0);
            $table->integer('max_retries')->default(3);
            $table->timestamp('next_retry_at')->nullable();
            $table->string('last_error_code', 255)->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->primary(['id']);
            $table->index(['created_by'], 'invoice_submissions_created_by_foreign');
            $table->index(['idempotency_id'], 'invoice_submissions_idempotency_id_foreign');
            $table->index(['invoice_id', 'state'], 'invoice_submissions_invoice_id_state_index');
            $table->index(['org_id', 'created_by', 'created_at'], 'invoice_submissions_organization_id_created_by_created_at_index');
            $table->index(['org_id', 'state'], 'invoice_submissions_organization_id_state_index');
            $table->index(['signed_at'], 'invoice_submissions_signed_at_index');
            $table->index(['state', 'next_retry_at'], 'invoice_submissions_state_next_retry_at_index');
            $table->index(['zatca_uuid'], 'invoice_submissions_zatca_uuid_index');
            $table->index(['org_id', 'created_at'], 'submissions_org_created_idx');
            $table->index(['state', 'created_at'], 'submissions_state_created_idx');
            $table->index(['state'], 'submissions_state_idx');
            $table->index(['submission_type'], 'submissions_type_idx');
            $table->foreign('created_by', 'invoice_submissions_created_by_foreign')->references('id')->on('users')->nullOnDelete();
            $table->foreign('idempotency_id', 'invoice_submissions_idempotency_id_foreign')->references('id')->on('submission_idempotency')->nullOnDelete();
            $table->foreign('invoice_id', 'invoice_submissions_invoice_id_foreign')->references('id')->on('invoices')->cascadeOnDelete();
            $table->foreign('org_id', 'invoice_submissions_organization_id_foreign')->references('id')->on('organizations')->cascadeOnDelete();
        });

        Schema::create('submission_state_logs', function (Blueprint $table) {
            $table->uuid('id');
            $table->uuid('submission_id');
            $table->string('from_state', 255)->nullable();
            $table->string('to_state', 255);
            $table->string('trigger', 255);
            $table->json('context')->nullable();
            $table->text('notes')->nullable();
            $table->string('actor_type', 255)->nullable();
            $table->uuid('actor_id')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->primary(['id']);
            $table->index(['submission_id', 'created_at'], 'submission_state_logs_submission_id_created_at_index');
            $table->foreign('submission_id', 'submission_state_logs_submission_id_foreign')->references('id')->on('invoice_submissions')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('submission_state_logs');
        Schema::dropIfExists('invoice_submissions');
        Schema::dropIfExists('submission_idempotency');
    }
};
