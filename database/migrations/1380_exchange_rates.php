<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The daily rate table an admin keeps in Admin › Finance › Settings: what
 * one unit of a currency is worth in the tax currency on a day.
 *
 * An invoice in another currency states its VAT in the tax currency too, at
 * the rate it records (invoices.exchange_rate); this is where that rate can
 * come from when the exchange-rate source is the table. One rate per
 * currency per day, corrected in place and never removed: an invoice keeps
 * the rate it was issued at, whatever happens here later.
 *
 * An entity (class a), the platform's own: who added and last changed it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('currency', 3);
            $table->date('rate_date');
            // Units of the tax currency for one unit of this one.
            $table->decimal('rate', 18, 6);
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['currency', 'rate_date'], 'exchange_rates_currency_date_unique');

            foreach (['created_by', 'updated_by'] as $actor) {
                $table->foreign($actor)->references('id')->on('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
    }
};
