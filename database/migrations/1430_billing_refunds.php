<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Money given back to a customer, and the credit note behind it.
 *
 * A refund against an invoice is a credit note the billing organization
 * issues through its country's module (credit_note_id, credited_amount
 * with VAT): the invoice then owes that much less. What the customer had
 * already paid beyond what it now owes is either kept to its credit
 * (billing_credits, source credit_note) or paid back (payout_amount) by
 * bank transfer or to the card it paid with.
 *
 * A credit with nothing invoiced behind it - an overpayment - is paid back
 * without a note: credit_id names it, and what is left of it shrinks.
 *
 * A financial record (class c): written once, never changed or deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_refunds', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id');
            $table->uuid('receivable_id')->nullable();
            $table->uuid('credit_note_id')->nullable();
            $table->uuid('credit_id')->nullable();
            $table->string('currency', 3);
            $table->decimal('credited_amount', 14, 2)->default(0);
            $table->decimal('payout_amount', 14, 2)->default(0);
            // credit, bank_transfer or card: where what was over went.
            $table->string('payout_method', 20);
            $table->string('reference', 100)->nullable();
            $table->string('gateway_reference', 100)->nullable();
            $table->string('reason', 500);
            $table->date('refunded_on');
            $table->uuid('created_by')->nullable();
            $table->string('created_via', 20)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['org_id', 'refunded_on']);
            $table->index('credit_id');
            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('receivable_id')->references('id')->on('receivables')->cascadeOnDelete();
            $table->foreign('credit_note_id')->references('id')->on('invoices');
            $table->foreign('credit_id')->references('id')->on('billing_credits')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_refunds');
    }
};
