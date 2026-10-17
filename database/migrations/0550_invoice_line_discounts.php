<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A discount on one line (BT-136): two for one, ten percent off the
 * shirts. As it was entered - with VAT in it on an invoice priced that
 * way - and already taken off the line's net, which is what the line's
 * VAT is charged on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->decimal('discount_amount', 12, 2)->nullable()->after('unit_price');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->dropColumn('discount_amount');
        });
    }
};
