<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Credit an organization holds against its next invoice: a fixed amount in
 * a currency, or a percentage off. Granted by something with a reason of
 * its own - a referral reward - and redeemed by invoicing (CreditSource),
 * which says on which invoice and for how much.
 *
 * A financial record (class c): its status moves - available, redeemed or
 * reversed - and it is never deleted. A reversal keeps who and why. An
 * available credit past expires_at is not offered any more.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_credits', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id');
            // What granted it (referral) and the record behind it.
            $table->string('source', 30);
            $table->uuid('source_id')->nullable();
            // amount: a sum in currency; percent: off the next invoice.
            $table->string('kind', 10);
            $table->decimal('amount', 12, 2)->nullable();
            $table->decimal('percent', 5, 2)->nullable();
            $table->string('currency', 3)->nullable();
            $table->string('description', 255);
            // available, redeemed or reversed.
            $table->string('status', 20);
            $table->timestamp('expires_at')->nullable();
            // Where it was used: the invoice, how much it took off, and when.
            $table->uuid('redeemed_invoice_id')->nullable();
            $table->decimal('redeemed_amount', 12, 2)->nullable();
            $table->timestamp('redeemed_at')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->uuid('reversed_by')->nullable();
            $table->string('reversal_reason', 500)->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('reversed_by')->references('id')->on('users')->nullOnDelete();

            // What invoicing asks for: an organization's available credit.
            $table->index(['org_id', 'status', 'currency'], 'account_credits_available_index');
            $table->index(['source', 'source_id'], 'account_credits_source_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_credits');
    }
};
