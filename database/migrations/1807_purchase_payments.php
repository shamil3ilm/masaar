<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Money an organization paid a supplier against one of its purchase
 * invoices, in the invoice's currency, on a day: where input tax is
 * recovered as the consideration is paid (UAE Cabinet Decision No. 52 of
 * 2017 Art 54(1)), the day decides the period.
 *
 * A financial record (class c): written once. A payment recorded in error is
 * answered by a second row below zero naming the one it reverses - unique,
 * so a payment is reversed once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id');
            $table->uuid('purchase_invoice_id');
            $table->decimal('amount', 14, 2);
            $table->date('paid_on');
            $table->string('reference', 100)->nullable();
            $table->uuid('reverses_id')->nullable();
            $table->uuid('created_by')->nullable();
            $table->string('created_via', 20)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['org_id', 'paid_on']);
            $table->index('purchase_invoice_id');
            $table->unique('reverses_id');
            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('purchase_invoice_id')->references('id')->on('purchase_invoices');
            $table->foreign('reverses_id')->references('id')->on('purchase_payments');
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_payments');
    }
};
