<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Creates the uae_fta_submissions table for UAE FTA Peppol PINT AE submissions
        Schema::create('uae_fta_submissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // foreignUuid, not foreignId: invoices.id and organizations.id are
            // UUIDs, and a bigint column cannot carry a foreign key to them.
            $table->foreignUuid('invoice_id')->constrained('invoices')->restrictOnDelete();
            $table->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->string('status')->default('draft');
            $table->string('fta_submission_id')->nullable()->unique();   // FTA-assigned ref
            $table->string('fta_validation_status')->nullable();         // PASS|WARNING|ERROR
            $table->json('fta_warnings')->nullable();
            $table->json('fta_errors')->nullable();
            $table->string('document_type', 3)->default('380');          // 380|381|383
            $table->longText('invoice_xml')->nullable();                 // Peppol UBL
            $table->unsignedSmallInteger('retry_count')->default(0);
            $table->unsignedSmallInteger('max_retries')->default(5);
            $table->timestamp('next_retry_at')->nullable();
            $table->string('last_error_message')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'status']);
            $table->index(['invoice_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('uae_fta_submissions');
    }
};
