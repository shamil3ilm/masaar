<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A supplier's invoice or credit note an organization received (Books): its
 * number and date, its totals in the currency billed and in the tax currency
 * at the document's own rate, the share of its input VAT the organization
 * may deduct, and the supplier's file.
 *
 * The input VAT side of the organization's return: KSA VAT Implementing
 * Regulations Art 49 (deduction, and holding the tax invoice, 49(7)) and
 * Art 59(2)(b); UAE Federal Decree-Law No. 8 of 2017 Art 54 and Cabinet
 * Decision No. 52 of 2017 Art 64(5)(h).
 *
 * A financial record (class c): never deleted. One entered by mistake is
 * void, with who, when and why; its status is what changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id');
            $table->uuid('supplier_id');
            $table->string('document_type', 20)->default('invoice');
            $table->uuid('original_id')->nullable();
            $table->string('supplier_invoice_number', 100);
            $table->date('invoice_date');
            $table->string('currency', 3);
            $table->decimal('exchange_rate', 18, 6)->nullable();
            $table->decimal('net', 14, 2);
            $table->decimal('vat', 14, 2);
            $table->decimal('gross', 14, 2);
            $table->decimal('net_tax', 14, 2);
            $table->decimal('vat_tax', 14, 2);
            $table->decimal('gross_tax', 14, 2);
            $table->decimal('deductible_share', 5, 2)->default(100);
            $table->decimal('deductible_vat_tax', 14, 2)->default(0);
            $table->string('description', 500)->nullable();
            $table->string('file_path', 255)->nullable();
            $table->string('file_name', 150)->nullable();
            $table->string('file_mime', 100)->nullable();
            $table->unsignedInteger('file_size')->nullable();
            $table->string('status', 20)->default('recorded');
            $table->string('void_reason', 500)->nullable();
            $table->uuid('voided_by')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->string('created_via', 20)->nullable();
            $table->timestamps();

            $table->index(['org_id', 'invoice_date']);
            $table->index(['supplier_id', 'supplier_invoice_number']);
            $table->index('original_id');
            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('supplier_id')->references('id')->on('book_suppliers');
            $table->foreign('original_id')->references('id')->on('purchase_invoices');
            $table->foreign('voided_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_invoices');
    }
};
