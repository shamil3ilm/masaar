<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An allocation is never changed: one made in error is answered by another
 * for the same amount below zero, naming the one it reverses. The
 * invoice's sums move back with it, and the record keeps both.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_allocations', function (Blueprint $table) {
            $table->uuid('reverses_id')->nullable();

            $table->unique('reverses_id');
            $table->foreign('reverses_id')->references('id')->on('payment_allocations');
        });
    }

    public function down(): void
    {
        Schema::table('payment_allocations', function (Blueprint $table) {
            $table->dropForeign(['reverses_id']);
            $table->dropUnique(['reverses_id']);
            $table->dropColumn('reverses_id');
        });
    }
};
