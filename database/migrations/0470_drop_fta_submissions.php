<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The UAE stub's submissions go.
 *
 * fta_submissions belonged to a client for a direct authority API that the
 * UAE's model does not have: invoices travel over Peppol through an
 * accredited service provider (peppol_documents, 0480). No deployment had
 * the UAE switched on, so the table holds nothing worth keeping.
 *
 * down() puts it back as 0190 created it and 0230 left it - a unique index
 * on invoice_id in place of the plain one - so rolling back past 0230 works.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('fta_submissions');
    }

    public function down(): void
    {
        Schema::create('fta_submissions', function (Blueprint $table) {
            $table->uuid('id');
            $table->uuid('invoice_id');
            $table->uuid('org_id');
            $table->string('status', 255)->default('draft');
            $table->string('reference', 255)->nullable();
            $table->string('validation_status', 255)->nullable();
            $table->json('warnings')->nullable();
            $table->json('errors')->nullable();
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
            $table->unique(['reference'], 'fta_submissions_reference_unique');
            $table->unique(['invoice_id'], 'fta_submissions_invoice_id_unique');
            $table->index(['org_id', 'status'], 'fta_submissions_organization_id_status_index');
            $table->foreign('invoice_id', 'fta_submissions_invoice_id_foreign')->references('id')->on('invoices')->restrictOnDelete();
            $table->foreign('org_id', 'fta_submissions_organization_id_foreign')->references('id')->on('organizations')->restrictOnDelete();
        });
    }
};
