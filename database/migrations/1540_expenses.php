<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the platform spent: one supplier's invoice or receipt, in the
 * currency it was billed in, with its VAT and how that VAT is treated
 * (Admin › Finance › Expenses).
 *
 * net, vat and gross are the supplier's figures. net_tax, vat_tax and
 * gross_tax are the same in the tax currency at exchange_rate - the daily
 * rate on the expense's date, from the rate table or entered (VAT
 * Implementing Regulations Art 61: an amount in another currency is
 * converted to riyals at the daily rate on the date the tax is due);
 * exchange_rate is null when the currency is the tax currency.
 *
 * vat_treatment says what the VAT return takes from it: recoverable input
 * VAT (recoverable_vat_tax), VAT that is a cost, no VAT, or reverse charge.
 * A supplier invoice number is unique per supplier among expenses not void,
 * which Expenses checks (not an index: a void one may be entered again).
 * The receipt is a file on the private receipts disk.
 *
 * A financial record (class c): never deleted - a mistaken one is void,
 * with who and why; its status moves as it is recorded and paid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('supplier_id');
            $table->string('status', 16);
            $table->date('expense_date');
            $table->date('due_date');
            $table->string('supplier_invoice_number', 80)->nullable();
            $table->string('description', 300);
            $table->string('category', 40);
            $table->string('cost_centre', 40)->nullable();
            $table->string('currency', 3);
            $table->decimal('net', 14, 2);
            $table->decimal('vat', 14, 2);
            $table->decimal('gross', 14, 2);
            $table->string('tax_currency', 3);
            // Units of the tax currency for one of currency, and where it
            // came from: entered, or the rate table's day.
            $table->decimal('exchange_rate', 18, 6)->nullable();
            $table->string('rate_source', 10)->nullable();
            $table->date('rate_date')->nullable();
            $table->decimal('net_tax', 14, 2);
            $table->decimal('vat_tax', 14, 2);
            $table->decimal('gross_tax', 14, 2);
            $table->string('vat_treatment', 20);
            $table->decimal('recoverable_vat_tax', 14, 2)->default(0);
            $table->string('receipt_path')->nullable();
            $table->string('receipt_name', 120)->nullable();
            $table->string('receipt_mime', 60)->nullable();
            $table->unsignedInteger('receipt_size')->nullable();
            $table->string('notes', 2000)->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->uuid('voided_by')->nullable();
            $table->string('void_reason', 500)->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->string('created_via', 20)->nullable();
            $table->timestamps();

            $table->index(['supplier_id', 'supplier_invoice_number']);
            $table->index(['status', 'due_date']);
            $table->index('expense_date');
            $table->foreign('supplier_id')->references('id')->on('suppliers');

            foreach (['voided_by', 'created_by', 'updated_by'] as $actor) {
                $table->foreign($actor)->references('id')->on('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
