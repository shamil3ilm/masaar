<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A refund on its way: written before anything is issued or sent, and
 * moved on as each step is done, so no step is lost and none is done twice
 * (Refunds).
 *
 *   reserved  what it credits (credited_amount, with VAT) is held against
 *             the invoice under a lock, so two refunds cannot both take
 *             its last riyal; the credit note is being issued
 *   noted     the credit note is issued (credit_note_id); the invoice owes
 *             less once the next step is done
 *   paying    the invoice is credited, and payout_amount is being sent back
 *             to the card payment payment_id - by the gateway, outside any
 *             database transaction, under this row's id as the refund's
 *             reference, so a retry never refunds twice
 *   done      the refund is recorded (refund_id)
 *   failed    nothing was issued or paid back; error says why
 *
 * A financial record (class c): its status moves, never deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_refund_intents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id');
            $table->uuid('receivable_id')->nullable();
            $table->uuid('credit_id')->nullable();
            $table->string('currency', 3);
            $table->decimal('credited_amount', 14, 2)->default(0);
            // credit, bank_transfer or card: where what is over was asked to go.
            $table->string('payout_method', 20);
            $table->decimal('payout_amount', 14, 2)->default(0);
            $table->uuid('payment_id')->nullable();
            $table->string('reference', 100)->nullable();
            $table->string('reason', 500);
            $table->string('status', 20);
            $table->uuid('credit_note_id')->nullable();
            $table->uuid('refund_id')->nullable();
            $table->string('error', 500)->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->index(['receivable_id', 'status']);
            $table->index(['credit_id', 'status']);
            $table->index('status');
            // As billing_refunds, which each becomes.
            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('receivable_id')->references('id')->on('receivables')->cascadeOnDelete();
            $table->foreign('credit_id')->references('id')->on('billing_credits')->cascadeOnDelete();
            $table->foreign('payment_id')->references('id')->on('payments')->cascadeOnDelete();
            $table->foreign('credit_note_id')->references('id')->on('invoices');
            $table->foreign('refund_id')->references('id')->on('billing_refunds');
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_refund_intents');
    }
};
