<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a purchase is to be paid, and what follows from it where input tax
 * depends on payment (UAE Federal Decree-Law No. 8 of 2017 Art 55(1)(b);
 * Cabinet Decision No. 52 of 2017 Art 54):
 *
 *   due_on          the agreed date for payment
 *   paid_later      the organization does not mean to pay within six months
 *                   of that date, so its input tax is recovered only as it
 *                   is paid (Art 54(1)) rather than on receipt (Art 54(2))
 *   written_off_on  the day the supplier told it the unpaid consideration
 *                   was written off, from which the input tax recovered on
 *                   it is reduced (Decree-Law Art 64(2))
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_invoices', function (Blueprint $table) {
            $table->date('due_on')->nullable();
            $table->boolean('paid_later')->default(false);
            $table->date('written_off_on')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_invoices', function (Blueprint $table) {
            $table->dropColumn(['due_on', 'paid_later', 'written_off_on']);
        });
    }
};
