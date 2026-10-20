<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A payment recorded in error is reversed, never deleted or edited: when,
 * by whom and why. Reconciliation reverses the payment a match made when
 * the match is undone (Payments::reverse); its allocations are answered by
 * reversing allocations, and what it left to the customer's credit is
 * reversed with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->timestamp('reversed_at')->nullable();
            $table->uuid('reversed_by')->nullable();
            $table->string('reversal_reason', 500)->nullable();

            $table->foreign('reversed_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['reversed_by']);
            $table->dropColumn(['reversed_at', 'reversed_by', 'reversal_reason']);
        });
    }
};
