<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Goods or services delivered to a customer and not yet invoiced: what a
 * month's summary tax invoice gathers (SummaryInvoices). A delivery note is
 * not a tax document - it carries no number in the invoice sequence, no
 * hash and no VAT of its own, and nothing is filed for it. The tax invoice
 * is the summary that lists it (KSA VAT Implementing Regulations Art 53(4);
 * UAE Cabinet Decision No. 52 of 2017 Art 59(6)).
 *
 * Its lines are kept as entered, priced net, with their VAT treatment, so
 * the summary invoice can state each supply and its date separately (ZATCA
 * Guideline for Tax Invoicing §4.4, Example 8).
 *
 * invoice_id is the summary invoice that took it: set when the summary is
 * drafted, cleared again if that draft is deleted, never changed after the
 * invoice is issued. An entity people may remove while nothing invoiced it
 * (class b).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_notes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id');
            $table->uuid('branch_id')->nullable();
            $table->uuid('customer_id');
            $table->string('number', 64);
            $table->date('delivered_on');
            $table->string('reference', 127)->nullable();
            $table->json('lines');
            $table->decimal('net_amount', 18, 2);
            $table->uuid('invoice_id')->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();
            $table->string('created_via', 20)->nullable();
            $table->foreignUuid('license_id')->nullable()->constrained('licenses')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['org_id', 'customer_id', 'delivered_on']);
            $table->index('invoice_id');
            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();
            $table->foreign('customer_id')->references('id')->on('customers');
            $table->foreign('invoice_id')->references('id')->on('invoices')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('deleted_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_notes');
    }
};
