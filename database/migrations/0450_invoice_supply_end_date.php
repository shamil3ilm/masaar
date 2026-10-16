<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The last day of a supply made over a period (KSA-24).
 *
 * A subscription is supplied from one date to another, and the invoice says
 * so: supply_date is when it starts, this is when it ends. Null for a supply
 * made on one day, which is most of them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->date('supply_end_date')->nullable()->after('supply_date');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('supply_end_date');
        });
    }
};
