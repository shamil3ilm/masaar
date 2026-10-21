<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A credit note from a supplier against one of its bills: what it takes
 * off the bill, before and after VAT, in the bill's currency and - at the
 * bill's own rate, so it undoes exactly what the bill booked - in the tax
 * currency, with the input VAT it takes back and, for reverse charge, the
 * self-assessed VAT on both sides (VAT Implementing Regulations Art 54: a
 * credit note corrects the VAT of the supply it names).
 *
 * A financial record (class c): written once, never changed or deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_credit_notes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('expense_id');
            $table->uuid('supplier_id');
            $table->date('credit_date');
            $table->string('supplier_credit_number', 100)->nullable();
            $table->string('currency', 3);
            $table->decimal('net', 14, 2);
            $table->decimal('vat', 14, 2);
            $table->decimal('gross', 14, 2);
            $table->decimal('exchange_rate', 18, 6)->nullable();
            $table->decimal('net_tax', 14, 2);
            $table->decimal('vat_tax', 14, 2);
            $table->decimal('gross_tax', 14, 2);
            $table->decimal('self_assessed_vat_tax', 14, 2)->default(0);
            $table->decimal('recoverable_vat_tax', 14, 2)->default(0);
            $table->string('reason', 500);
            $table->uuid('created_by')->nullable();
            $table->string('created_via', 20)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('expense_id');
            $table->index('credit_date');
            $table->index(['supplier_id', 'supplier_credit_number']);
            $table->foreign('expense_id')->references('id')->on('expenses');
            $table->foreign('supplier_id')->references('id')->on('suppliers');
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_credit_notes');
    }
};
