<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which money settled which invoice: part of a payment, or of a credit,
 * set against one receivable, in the receivable's currency.
 *
 * Exactly one of payment_id and credit_id. One payment can settle several
 * invoices and one invoice be settled by several payments; what a payment
 * leaves unallocated becomes the customer's credit (billing_credits).
 *
 * fx_difference is the exchange difference in the tax currency when a
 * payment in another currency was received at a rate other than the
 * invoice's: the amount at the payment's rate less the same at the
 * invoice's, a gain when positive.
 *
 * A financial record (class c): never changed or deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id');
            $table->uuid('receivable_id');
            $table->uuid('payment_id')->nullable();
            $table->uuid('credit_id')->nullable();
            $table->decimal('amount', 14, 2);
            $table->decimal('fx_difference', 14, 2)->nullable();
            $table->date('allocated_on');
            $table->uuid('created_by')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('receivable_id');
            $table->index('payment_id');
            $table->index('credit_id');
            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('receivable_id')->references('id')->on('receivables')->cascadeOnDelete();
            $table->foreign('payment_id')->references('id')->on('payments')->cascadeOnDelete();
            $table->foreign('credit_id')->references('id')->on('billing_credits')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_allocations');
    }
};
