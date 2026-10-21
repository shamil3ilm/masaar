<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A payment to a supplier recorded in error is answered, never edited: a
 * second row for the same amounts below zero, on the day it was taken
 * back, naming the one it reverses. Unique, so a payment is reversed once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_payments', function (Blueprint $table) {
            $table->uuid('reverses_id')->nullable();

            $table->unique('reverses_id');
            $table->foreign('reverses_id')->references('id')->on('supplier_payments');
        });
    }

    public function down(): void
    {
        Schema::table('supplier_payments', function (Blueprint $table) {
            $table->dropForeign(['reverses_id']);
            $table->dropUnique(['reverses_id']);
            $table->dropColumn('reverses_id');
        });
    }
};
