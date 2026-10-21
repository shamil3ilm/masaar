<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Money an organization's customer paid against one of its issued invoices:
 * how much, in the invoice's currency, on which day and how (Books
 * receivables). The invoice itself never changes once issued, so what is
 * owed on it is read from these rows and the notes against it.
 *
 * A financial record (class c): written once. A payment recorded in error is
 * answered by a second row for the same amount below zero, naming the one it
 * reverses - unique, so a payment is reversed once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id');
            $table->uuid('invoice_id');
            $table->decimal('amount', 14, 2);
            $table->string('currency', 3);
            $table->date('paid_on');
            $table->string('method', 20);
            $table->string('reference', 100)->nullable();
            $table->string('notes', 500)->nullable();
            $table->uuid('reverses_id')->nullable();
            $table->uuid('created_by')->nullable();
            $table->string('created_via', 20)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['org_id', 'paid_on']);
            $table->index('invoice_id');
            $table->unique('reverses_id');
            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('invoice_id')->references('id')->on('invoices');
            $table->foreign('reverses_id')->references('id')->on('invoice_payments');
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_payments');
    }
};
