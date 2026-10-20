<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Money the platform paid a supplier against one of its expenses: in full,
 * or one of several part payments.
 *
 * channel is how it went out - bank (a transfer from one of the platform's
 * accounts, paid_from naming it) or card - and reference is what the bank
 * statement or the card's report shows for it: what a reconciliation of
 * outgoing payments matches a statement line against. amount is in the
 * expense's currency; amount_tax the same in the tax currency at the
 * expense's rate.
 *
 * A financial record (class c): never changed or deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('expense_id');
            $table->uuid('supplier_id');
            $table->date('paid_on');
            $table->string('currency', 3);
            $table->decimal('amount', 14, 2);
            $table->decimal('amount_tax', 14, 2);
            $table->string('channel', 10);
            $table->string('reference', 100)->nullable();
            $table->string('paid_from', 120)->nullable();
            $table->string('notes', 1000)->nullable();
            $table->uuid('created_by')->nullable();
            $table->string('created_via', 20)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('expense_id');
            $table->index(['supplier_id', 'paid_on']);
            $table->index(['channel', 'paid_on']);
            $table->index('reference');
            $table->foreign('expense_id')->references('id')->on('expenses');
            $table->foreign('supplier_id')->references('id')->on('suppliers');
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_payments');
    }
};
