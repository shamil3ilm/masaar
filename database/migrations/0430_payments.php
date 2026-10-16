<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payments for plans, recorded by hand.
 *
 * Renewing a licence used to mean editing its expiry, with nothing kept of
 * what was paid, when, or which invoice covered it. A payment row is that
 * record; invoice_id is the platform's own tax invoice for it, issued from
 * the billing organization once it can be.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id');
            $table->uuid('license_id')->nullable();
            $table->string('tier', 20);
            $table->unsignedSmallInteger('months');
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('amount', 12, 2);
            $table->decimal('vat_amount', 12, 2);
            $table->decimal('total', 12, 2);
            $table->string('currency', 3);
            $table->string('method', 20);
            $table->string('reference', 100)->nullable();
            $table->date('paid_at');
            $table->text('notes')->nullable();
            $table->uuid('recorded_by')->nullable();
            // The invoice the billing organization issued for it, and why it
            // has none yet when it has none.
            $table->uuid('invoice_id')->nullable();
            $table->string('invoice_error', 500)->nullable();
            $table->timestamps();

            $table->index(['org_id', 'paid_at']);
            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
