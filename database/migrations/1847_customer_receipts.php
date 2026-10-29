<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Money a customer paid on account, before it is set against an invoice
 * (Books CustomerPayments): received, then used to pay invoices - an
 * invoice payment naming it (invoice_payments.receipt_id) - or paid back.
 *
 * A financial record (class c): written once. A receipt is the row with
 * kind 'receipt'; a refund of what is left on it is a row below zero naming
 * it (receipt_id); a mistake is answered by a reversal naming the row it
 * reverses (reverses_id, unique, so a row is reversed once).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_receipts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id');
            $table->uuid('branch_id')->nullable();
            $table->uuid('customer_id');
            $table->string('kind', 20);
            $table->uuid('receipt_id')->nullable();
            $table->uuid('reverses_id')->nullable();
            $table->decimal('amount', 14, 2);
            $table->string('currency', 3);
            $table->date('received_on');
            $table->string('method', 20);
            $table->string('reference', 100)->nullable();
            $table->string('notes', 500)->nullable();
            $table->uuid('created_by')->nullable();
            $table->string('created_via', 20)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['org_id', 'customer_id']);
            $table->index('receipt_id');
            $table->unique('reverses_id');
            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();
            $table->foreign('customer_id')->references('id')->on('customers');
            $table->foreign('receipt_id')->references('id')->on('customer_receipts');
            $table->foreign('reverses_id')->references('id')->on('customer_receipts');
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('invoice_payments', function (Blueprint $table) {
            $table->uuid('receipt_id')->nullable()->after('reverses_id');

            $table->index('receipt_id');
            $table->foreign('receipt_id')->references('id')->on('customer_receipts');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_payments', function (Blueprint $table) {
            $table->dropForeign(['receipt_id']);
            $table->dropIndex(['receipt_id']);
            $table->dropColumn('receipt_id');
        });

        Schema::dropIfExists('customer_receipts');
    }
};
