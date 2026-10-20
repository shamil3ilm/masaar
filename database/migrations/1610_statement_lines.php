<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One line of an imported statement or settlement report: money in
 * (credit) or out (debit), as the bank or the gateway printed it.
 *
 *   line_hash   the line's fingerprint within its source - date, amount,
 *               direction, references, text and its place among identical
 *               lines - so a line in two overlapping statements is kept
 *               once.
 *   amount      always positive; direction says which way. For a
 *               settlement line it is the gross the customer paid, with
 *               fee_amount what the gateway kept.
 *   status      unmatched, suggested, matched or ignored, moved by
 *               reconciliation; who ignored it and why is kept.
 *
 * A financial record (class c): never deleted, its status moves.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('statement_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('import_id');
            $table->string('source', 40);
            $table->char('line_hash', 64);
            $table->unsignedInteger('sequence');
            $table->date('booked_on');
            $table->date('value_on')->nullable();
            $table->string('direction', 6);
            $table->decimal('amount', 15, 2);
            $table->string('currency', 3);
            $table->string('reference', 140)->nullable();
            $table->string('bank_reference', 140)->nullable();
            $table->string('counterparty_name', 140)->nullable();
            $table->string('counterparty_account', 64)->nullable();
            $table->string('description', 500)->nullable();
            $table->string('gateway_reference', 100)->nullable();
            $table->decimal('fee_amount', 15, 2)->nullable();
            $table->string('status', 12);
            $table->string('ignored_reason', 500)->nullable();
            $table->timestamp('ignored_at')->nullable();
            $table->uuid('ignored_by')->nullable();
            $table->timestamps();

            $table->unique(['source', 'line_hash']);
            $table->index(['status', 'currency']);
            $table->index('booked_on');
            $table->index('gateway_reference');
            $table->foreign('import_id')->references('id')->on('statement_imports');
            $table->foreign('ignored_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('statement_lines');
    }
};
