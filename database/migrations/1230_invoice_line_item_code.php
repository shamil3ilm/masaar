<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The item code a line printed, copied from its product when the invoice is
 * issued (LineSnapshot), so a printed invoice stays the one issued after
 * the product's code changes or the product goes. As long as a product's
 * own (products.sku). Null for a line made from no product, and for a
 * draft's lines until it is issued.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->string('item_code', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->dropColumn('item_code');
        });
    }
};
