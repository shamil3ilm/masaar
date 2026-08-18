<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offline_queue', function (Blueprint $table) {
            $table->uuid('id');
            $table->uuid('invoice_id');
            $table->uuid('org_id');
            $table->longText('signed_xml');
            $table->string('invoice_hash', 64);
            $table->text('qr_code');
            $table->enum('state', ['pending', 'processing', 'completed', 'failed', 'cancelled'])->default('pending');
            $table->enum('priority', ['low', 'normal', 'high', 'critical'])->default('normal');
            $table->integer('attempts')->default(0);
            $table->integer('max_attempts')->default(3);
            $table->timestamp('next_attempt_at')->nullable();
            $table->text('last_error')->nullable();
            $table->json('zatca_response')->nullable();
            $table->timestamp('queued_at');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 255)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->primary(['id']);
            $table->index(['invoice_id'], 'offline_queue_invoice_id_index');
            $table->index(['org_id', 'state'], 'offline_queue_organization_id_state_index');
            $table->index(['state', 'next_attempt_at'], 'offline_queue_state_next_attempt_at_index');
            $table->index(['state', 'priority', 'queued_at'], 'offline_queue_state_priority_queued_at_index');
            $table->index(['state', 'updated_at'], 'offline_queue_state_updated_idx');
            $table->foreign('invoice_id', 'offline_queue_invoice_id_foreign')->references('id')->on('invoices')->cascadeOnDelete();
            $table->foreign('org_id', 'offline_queue_organization_id_foreign')->references('id')->on('organizations')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offline_queue');
    }
};
