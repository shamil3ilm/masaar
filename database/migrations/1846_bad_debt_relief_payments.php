<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The customer payment a bad-debt row answers: the payment after relief a
 * recovery came from, and the reversal of that payment that undoes it
 * (SalesBadDebts::undoRecovery), so taking a payment back takes its
 * recovery back too.
 *
 * by_credit_note marks an undoing whose tax goes through a credit note
 * instead: the tax invoice drafted for the recovery was already issued, and
 * the person is told to issue a credit note against it, which the return
 * counts - not this row as well.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bad_debt_reliefs', function (Blueprint $table) {
            $table->uuid('invoice_payment_id')->nullable()->after('relief_id');
            $table->boolean('by_credit_note')->default(false)->after('unrelated_confirmed');

            $table->index('invoice_payment_id');
            $table->foreign('invoice_payment_id')->references('id')->on('invoice_payments');
        });
    }

    public function down(): void
    {
        Schema::table('bad_debt_reliefs', function (Blueprint $table) {
            $table->dropForeign(['invoice_payment_id']);
            $table->dropIndex(['invoice_payment_id']);
            $table->dropColumn(['invoice_payment_id', 'by_credit_note']);
        });
    }
};
