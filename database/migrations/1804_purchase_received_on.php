<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The day the organization received a supplier's invoice or credit note:
 * the input VAT on it is deducted in the return for that day's period, the
 * first in which the supply has happened and the invoice is held (KSA VAT
 * Implementing Regulations Art 49(7), (8); UAE Federal Decree-Law No. 8 of
 * 2017 Art 55(1)). Never before the document's date; those already
 * recorded were received on it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_invoices', function (Blueprint $table) {
            $table->date('received_on')->nullable();

            $table->index(['org_id', 'received_on']);
        });

        DB::table('purchase_invoices')->whereNull('received_on')->update(['received_on' => DB::raw('invoice_date')]);
    }

    public function down(): void
    {
        Schema::table('purchase_invoices', function (Blueprint $table) {
            $table->dropIndex(['org_id', 'received_on']);
            $table->dropColumn('received_on');
        });
    }
};
