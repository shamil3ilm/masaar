<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a match joins, each with its amount:
 *
 *   line        a statement line it matches
 *   receivable  an invoice the money goes to, and how much
 *   payment     a payment already recorded that the line is
 *   created     a payment the confirmed match recorded for a line
 *               (statement_line_id and payment_id): reversed with it
 *
 * Written once, never changed (class c, append-only).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reconciliation_match_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('match_id');
            $table->string('role', 12);
            $table->uuid('statement_line_id')->nullable();
            $table->uuid('receivable_id')->nullable();
            $table->uuid('payment_id')->nullable();
            $table->decimal('amount', 15, 2);
            $table->timestamp('created_at')->nullable();

            $table->index(['match_id', 'role']);
            $table->index('statement_line_id');
            $table->index('receivable_id');
            $table->index('payment_id');
            $table->foreign('match_id')->references('id')->on('reconciliation_matches')->cascadeOnDelete();
            $table->foreign('statement_line_id')->references('id')->on('statement_lines');
            $table->foreign('receivable_id')->references('id')->on('receivables');
            $table->foreign('payment_id')->references('id')->on('payments');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliation_match_items');
    }
};
