<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What happened to an issued invoice after it was issued, and the documents
 * that answer it: a return, an exchange, a discount for damage, a
 * replacement, a correction of the buyer's details (KSA VAT Implementing
 * Regulations Art 40(1); UAE Federal Decree-Law No. 8 of 2017 Art 61(1)),
 * or a payment received after bad-debt relief (KSA Art 40(9)).
 *
 * Each row names the invoice, the kind of event, why, the lines and
 * quantities it was about, and the documents made for it - the credit or
 * debit note, the new invoice, the relief it follows. A free replacement
 * makes no tax document and is recorded here alone. The issued invoice is
 * never changed: everything is a new row or a new document.
 *
 * A compliance record (class c): written once, never changed or removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_adjustments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id');
            $table->uuid('branch_id')->nullable();
            $table->uuid('invoice_id');
            $table->string('kind', 30);
            $table->string('reason', 500)->nullable();
            $table->json('details')->nullable();
            $table->uuid('note_id')->nullable();
            $table->uuid('new_invoice_id')->nullable();
            $table->uuid('bad_debt_relief_id')->nullable();
            $table->uuid('created_by')->nullable();
            $table->string('created_via', 20)->nullable();
            $table->foreignUuid('license_id')->nullable()->constrained('licenses')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['org_id', 'invoice_id']);
            $table->index('new_invoice_id');
            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();
            $table->foreign('invoice_id')->references('id')->on('invoices');
            $table->foreign('note_id')->references('id')->on('invoices')->nullOnDelete();
            $table->foreign('new_invoice_id')->references('id')->on('invoices')->nullOnDelete();
            $table->foreign('bad_debt_relief_id')->references('id')->on('bad_debt_reliefs');
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_adjustments');
    }
};
