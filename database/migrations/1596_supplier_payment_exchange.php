<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A payment to a supplier in another currency, in riyals at its own day's
 * rate, and the exchange difference it realises.
 *
 * amount_tax is now the payment at exchange_rate - the rate table's on the
 * payment date, or one entered (rate_source, rate_date). settled_tax is
 * what the part it settles was booked at on the expense - pro rata, the
 * last payment taking what is left - and exchange_difference is
 * settled_tax less amount_tax: a gain when positive, a loss when negative
 * (VAT Implementing Regulations Art 61 for the daily rate).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_payments', function (Blueprint $table) {
            $table->decimal('exchange_rate', 18, 6)->nullable();
            $table->string('rate_source', 10)->nullable();
            $table->date('rate_date')->nullable();
            $table->decimal('settled_tax', 14, 2)->default(0);
            $table->decimal('exchange_difference', 14, 2)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('supplier_payments', function (Blueprint $table) {
            $table->dropColumn(['exchange_rate', 'rate_source', 'rate_date', 'settled_tax', 'exchange_difference']);
        });
    }
};
