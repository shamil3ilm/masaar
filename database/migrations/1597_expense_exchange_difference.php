<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The exchange difference realised on an expense so far: its payments'
 * (supplier_payments.exchange_difference) summed, so a list or a report
 * reads it in one query. A gain when positive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->decimal('exchange_difference', 14, 2)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropColumn('exchange_difference');
        });
    }
};
