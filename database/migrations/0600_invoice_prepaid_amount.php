<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a final invoice's prepayments came to with their VAT (BT-113). The
 * buyer pays the total less it. Null for an invoice that settles none.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->decimal('prepaid_amount', 12, 2)->nullable()->after('total');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('prepaid_amount');
        });
    }
};
