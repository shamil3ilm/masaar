<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What kind of line an invoice line is, where it is not an ordinary sale:
 * 'disbursement', a cost paid in the customer's name and for its account,
 * outside the value of the supply (GCC VAT Agreement Art 26(6)(c)) - out of
 * scope, never charged VAT, and shown apart on the printed invoice and in
 * the reports. Null for every other line.
 *
 * Part of the line, so frozen with its invoice like the rest of it
 * (migration 1814's triggers refuse any change to an issued invoice's lines).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->string('kind', 20)->nullable()->after('product_id');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->dropColumn('kind');
        });
    }
};
