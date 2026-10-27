<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which specified goods a domestic reverse-charge purchase line is, by the
 * country's code (UAE Federal Decree-Law No. 8 of 2017 Art 48(3), (8): the
 * codes of the e-invoice's goods type). Empty on every other line.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_lines', function (Blueprint $table) {
            $table->string('goods_type', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_lines', function (Blueprint $table) {
            $table->dropColumn('goods_type');
        });
    }
};
