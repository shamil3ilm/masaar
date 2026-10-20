<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A payment that is not for a plan: money a customer sent against its
 * invoices, by bank transfer or by card, set against them by allocations.
 *
 * Such a payment has no plan, months or period, so those become optional;
 * a plan payment keeps them as it always has. What reconciliation will
 * match a payment by comes with it:
 *
 *   channel            bank (a transfer into one of the platform's bank
 *                      accounts, matched against that account's statement)
 *                      or gateway (a card payment, matched against the card
 *                      gateway's settlement report); null for one recorded
 *                      another way (cash, cheque).
 *   bank_account       which of Finance › Settings' accounts it arrived in.
 *   gateway_reference  the gateway's transaction id, once per channel.
 *   fee_amount         what the bank or the gateway kept, in the payment's
 *                      currency: the customer is credited the full amount.
 *   exchange_rate      the rate to the tax currency on the day it arrived,
 *                      for a payment in another currency: the exchange
 *                      difference against each invoice's own rate is kept
 *                      on the allocation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('tier', 20)->nullable()->change();
            $table->unsignedSmallInteger('months')->nullable()->change();
            $table->date('period_start')->nullable()->change();
            $table->date('period_end')->nullable()->change();
            $table->string('channel', 20)->nullable();
            $table->string('bank_account', 40)->nullable();
            $table->string('gateway_reference', 100)->nullable();
            $table->decimal('fee_amount', 12, 2)->nullable();
            $table->decimal('exchange_rate', 18, 6)->nullable();

            $table->unique(['channel', 'gateway_reference'], 'payments_gateway_reference_unique');
            $table->index(['channel', 'paid_at']);
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique('payments_gateway_reference_unique');
            $table->dropIndex(['channel', 'paid_at']);
            $table->dropColumn(['channel', 'bank_account', 'gateway_reference', 'fee_amount', 'exchange_rate']);
        });
    }
};
