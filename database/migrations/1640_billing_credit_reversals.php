<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A credit granted for an overpayment that is reversed goes with it: when,
 * by whom and why. Its amount never changes; a reversed credit has nothing
 * left for an invoice to take. So a credit's row can now change once, and
 * keeps when.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_credits', function (Blueprint $table) {
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->uuid('reversed_by')->nullable();
            $table->string('reversal_reason', 500)->nullable();

            $table->foreign('reversed_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('billing_credits', function (Blueprint $table) {
            $table->dropForeign(['reversed_by']);
            $table->dropColumn(['updated_at', 'reversed_at', 'reversed_by', 'reversal_reason']);
        });
    }
};
