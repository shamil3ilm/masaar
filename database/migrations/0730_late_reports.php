<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A document not reported within twenty-four hours of its issue, and what
 * was done about it (ZATCA Detailed Guidelines for E-Invoicing, p48): when
 * the organization was told to notify ZATCA through its website form, when
 * someone recorded doing so, when the document was finally reported, when
 * the organization was told to notify ZATCA again, and when someone recorded
 * that. One row per document.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('late_reports', function (Blueprint $table) {
            $table->id();
            $table->uuid('org_id');
            $table->uuid('invoice_id')->unique();
            $table->timestamp('issued_at');
            $table->timestamp('alerted_at')->nullable();
            $table->timestamp('zatca_notified_at')->nullable();
            $table->uuid('zatca_notified_by')->nullable();
            $table->timestamp('reported_at')->nullable();
            $table->timestamp('resolved_alerted_at')->nullable();
            $table->timestamp('zatca_renotified_at')->nullable();
            $table->uuid('zatca_renotified_by')->nullable();
            $table->timestamps();

            $table->index(['org_id', 'reported_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('late_reports');
    }
};
