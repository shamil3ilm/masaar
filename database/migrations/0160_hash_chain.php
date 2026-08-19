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
    }

    public function down(): void
    {
        Schema::dropIfExists('hash_chain_history');
        Schema::dropIfExists('hash_chain_state');
    }
};
