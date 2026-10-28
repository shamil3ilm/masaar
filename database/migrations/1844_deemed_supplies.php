<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deemed supplies recorded in Books (DeemedSupplies): goods or services a
 * business gives away or takes for a non-business use on which it deducted
 * input tax, and what it keeps when it deregisters. Each records what was
 * supplied, to whom, on what day and at what value, the share of its input
 * tax deducted, and the outcome: taxed (with the invoice that states the
 * output tax, kept rather than given to anyone), relieved under a threshold,
 * or not a deemed supply at all - and why.
 *
 * KSA VAT Implementing Regulations Art 15 (nominal supplies) and the value
 * article for nominal supplies; UAE Federal Decree-Law No. 8 of 2017 Art 11,
 * 12, 37 and Cabinet Decision No. 52 of 2017 Art 5.
 *
 * A financial record (class c): written once, never changed or removed.
 * invoice_id clears if its draft is deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deemed_supplies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id');
            $table->uuid('branch_id')->nullable();
            $table->string('kind', 20);
            $table->string('description', 200);
            $table->string('description_ar', 200)->nullable();
            $table->string('recipient', 255)->nullable();
            $table->date('supplied_on');
            $table->decimal('value', 18, 2);
            $table->decimal('input_tax_percent', 5, 2);
            $table->string('outcome', 20);
            $table->string('reason', 500);
            $table->decimal('taxable_value', 18, 2)->default(0);
            $table->decimal('tax_before_cap', 18, 2)->default(0);
            $table->decimal('tax_amount', 18, 2)->default(0);
            $table->uuid('invoice_id')->nullable();
            $table->uuid('created_by')->nullable();
            $table->string('created_via', 20)->nullable();
            $table->foreignUuid('license_id')->nullable()->constrained('licenses')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['org_id', 'supplied_on']);
            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();
            $table->foreign('invoice_id')->references('id')->on('invoices')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deemed_supplies');
    }
};
