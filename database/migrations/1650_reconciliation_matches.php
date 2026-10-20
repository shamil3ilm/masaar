<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Statement lines matched to one customer's money: suggested by the
 * matcher or made by an admin, then confirmed, rejected, superseded by
 * another, or - once confirmed - reversed. What it joins is its items
 * (reconciliation_match_items).
 *
 *   rule        reference, amount, combined, partial or manual: how it was
 *               found.
 *   confidence  0 to 100. Below Matcher::HIGH an admin confirms it, and is
 *               asked first; nothing below it is confirmed automatically.
 *   basis       why, as codes the screen words: invoice_number,
 *               amount_exact, date_window, payer_name ...
 *   difference  the lines less what they were matched to: below zero
 *               short, above zero over (kept to the customer's credit).
 *   fee_amount  what of a shortfall was taken as the bank's fee.
 *
 * A financial record (class c): never deleted, its status moves, each
 * move with who and when.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reconciliation_matches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id');
            $table->string('status', 12);
            $table->string('rule', 12);
            $table->unsignedTinyInteger('confidence');
            $table->json('basis')->nullable();
            $table->string('currency', 3);
            $table->decimal('lines_amount', 15, 2);
            $table->decimal('targets_amount', 15, 2);
            $table->decimal('difference', 15, 2);
            $table->decimal('fee_amount', 15, 2)->nullable();
            $table->boolean('auto_confirmed')->default(false);
            $table->uuid('created_by')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->uuid('confirmed_by')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->uuid('rejected_by')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->uuid('reversed_by')->nullable();
            $table->string('reversal_reason', 500)->nullable();
            $table->timestamps();

            $table->index(['status', 'confidence']);
            $table->index(['org_id', 'status']);
            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('confirmed_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('rejected_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('reversed_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliation_matches');
    }
};
