<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('variance_logs', function (Blueprint $table) {
            $table->uuid('id');
            $table->uuid('org_id');
            $table->uuid('invoice_id')->nullable();
            $table->string('variance_type', 50);
            $table->string('rule_code', 50)->nullable();
            $table->json('sandbox_result')->nullable();
            $table->json('production_result')->nullable();
            $table->string('payload_hash', 64)->nullable();
            $table->boolean('reported_to_zatca')->default(false);
            $table->string('zatca_ticket_id', 100)->nullable();
            $table->text('notes')->nullable();
            $table->string('resolution_status', 50)->default('open');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->primary(['id']);
            $table->index(['invoice_id'], 'variance_logs_invoice_id_index');
            $table->index(['org_id'], 'variance_logs_organization_id_index');
            $table->index(['org_id', 'variance_type'], 'variance_logs_organization_id_variance_type_index');
            $table->index(['variance_type', 'created_at'], 'variance_logs_variance_type_created_at_index');
            $table->foreign('org_id', 'variance_logs_organization_id_foreign')->references('id')->on('organizations')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('variance_logs');
    }
};
