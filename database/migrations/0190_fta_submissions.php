<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fta_submissions', function (Blueprint $table) {
            $table->uuid('id');
            $table->uuid('invoice_id');
            $table->uuid('org_id');
            $table->string('status', 255)->default('draft');
            $table->string('fta_submission_id', 255)->nullable();
            $table->string('fta_validation_status', 255)->nullable();
            $table->json('fta_warnings')->nullable();
            $table->json('fta_errors')->nullable();
            $table->string('document_type', 3)->default('380');
            $table->longText('invoice_xml')->nullable();
            $table->unsignedSmallInteger('retry_count')->default(0);
            $table->unsignedSmallInteger('max_retries')->default(5);
            $table->timestamp('next_retry_at')->nullable();
            $table->string('last_error', 255)->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->primary(['id']);
            $table->unique(['fta_submission_id'], 'fta_submissions_fta_submission_id_unique');
            $table->index(['invoice_id'], 'fta_submissions_invoice_id_index');
            $table->index(['org_id', 'status'], 'fta_submissions_organization_id_status_index');
            $table->foreign('invoice_id', 'fta_submissions_invoice_id_foreign')->references('id')->on('invoices')->restrictOnDelete();
            $table->foreign('org_id', 'fta_submissions_organization_id_foreign')->references('id')->on('organizations')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fta_submissions');
    }
};
