<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hash_chain_state', function (Blueprint $table) {
            $table->uuid('org_id');
            $table->string('last_hash', 64);
            $table->unsignedBigInteger('last_icv');
            $table->uuid('last_invoice_id');
            $table->string('certificate_id', 64);
            $table->json('cert_transition')->nullable();
            $table->timestamp('updated_at');
            $table->primary(['org_id']);
            $table->foreign('org_id', 'hash_chain_state_organization_id_foreign')->references('id')->on('organizations')->cascadeOnDelete();
        });

        Schema::create('hash_chain_history', function (Blueprint $table) {
            $table->uuid('id');
            $table->uuid('org_id');
            $table->uuid('invoice_id');
            $table->string('invoice_hash', 64);
            $table->string('previous_hash', 64);
            $table->unsignedBigInteger('icv');
            $table->string('certificate_id', 64);
            $table->json('cert_transition')->nullable();
            $table->timestamp('created_at');
            $table->primary(['id']);
            $table->index(['certificate_id', 'created_at'], 'hash_chain_history_certificate_id_created_at_index');
            $table->index(['invoice_id'], 'hash_chain_history_invoice_id_index');
            $table->index(['org_id', 'certificate_id'], 'hash_chain_history_organization_id_certificate_id_index');
            $table->index(['org_id', 'icv'], 'hash_chain_history_organization_id_icv_index');
            $table->foreign('invoice_id', 'hash_chain_history_invoice_id_foreign')->references('id')->on('invoices')->cascadeOnDelete();
            $table->foreign('org_id', 'hash_chain_history_organization_id_foreign')->references('id')->on('organizations')->cascadeOnDelete();
        });

        Schema::create('certificate_lineage', function (Blueprint $table) {
            $table->uuid('id');
            $table->uuid('org_id');
            $table->string('certificate_id', 64);
            $table->string('cert_serial', 255);
            $table->string('certificate_hash', 64)->nullable();
            $table->string('issuer', 255);
            $table->timestamp('valid_from');
            $table->timestamp('valid_to');
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->unsignedBigInteger('first_icv')->nullable();
            $table->unsignedBigInteger('last_icv')->nullable();
            $table->enum('status', ['active', 'expired', 'revoked', 'superseded']);
            $table->uuid('superseded_by')->nullable();
            $table->text('transition_reason')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->primary(['id']);
            $table->index(['valid_to'], 'cert_lineage_valid_to_idx');
            $table->unique(['certificate_id'], 'certificate_lineage_certificate_id_unique');
            $table->index(['org_id', 'status', 'activated_at'], 'certificate_lineage_organization_id_status_activated_at_index');
            $table->index(['org_id', 'status'], 'certificate_lineage_organization_id_status_index');
            $table->index(['valid_to', 'status'], 'certificate_lineage_valid_to_status_index');
            $table->foreign('org_id', 'certificate_lineage_organization_id_foreign')->references('id')->on('organizations')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('certificate_lineage');
        Schema::dropIfExists('hash_chain_history');
        Schema::dropIfExists('hash_chain_state');
    }
};
