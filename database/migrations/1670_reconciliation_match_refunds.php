<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Money out matched too: a statement line of money paid back to a customer
 * is the refund already recorded for it (billing_refunds), and a match's
 * item names that refund (role refund). Nothing new is recorded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reconciliation_match_items', function (Blueprint $table) {
            $table->uuid('refund_id')->nullable();

            $table->index('refund_id');
            $table->foreign('refund_id')->references('id')->on('billing_refunds');
        });
    }

    public function down(): void
    {
        Schema::table('reconciliation_match_items', function (Blueprint $table) {
            $table->dropForeign(['refund_id']);
            $table->dropIndex(['refund_id']);
            $table->dropColumn('refund_id');
        });
    }
};
