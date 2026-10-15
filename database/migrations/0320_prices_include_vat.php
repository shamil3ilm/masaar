<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prices entered with VAT in them, as well as without.
 *
 * An organization sets which it uses by default; an invoice records which it
 * was drafted under, since a business selling both retail and to businesses
 * uses both. A line keeps its exact net: backed out of a gross price, it is
 * not always the rounded unit price times the quantity, and ZATCA is given
 * the net.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->boolean('prices_include_vat')->default(false);
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->boolean('prices_include_vat')->default(false);
        });

        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->decimal('net_amount', 14, 2)->nullable()->after('unit_price');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->dropColumn('net_amount');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('prices_include_vat');
        });

        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('prices_include_vat');
        });
    }
};
