<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A contract billed in stages - construction, contracting - with retention
 * withheld from each stage and released later (ProgressBilling).
 *
 * contracts: the customer, the contract's reference (BT-12 on every invoice
 * it makes), its total value before VAT, how it is taxed, and the share of
 * each stage withheld as retention. An entity (class a).
 *
 * contract_stages: the stages as agreed, each a value before VAT, entered as
 * an amount or a percentage of the contract. Rows of their contract.
 *
 * contract_invoices: each invoice the contract made - a stage's, for the
 * stage's value less the retention withheld, or a release of retention -
 * with the amounts behind it, which the invoice itself does not state (UAE
 * Electronic Invoicing Guidelines v1.1, scenario 5: the retention
 * calculation goes on a separate commercial document, not on the
 * e-invoice). Removed with the invoice when a draft is deleted, so a stage
 * can be invoiced again; an issued invoice is never deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contracts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id');
            $table->uuid('branch_id')->nullable();
            $table->uuid('customer_id');
            $table->string('reference', 127);
            $table->string('title', 255);
            $table->string('title_ar', 255)->nullable();
            $table->decimal('value', 18, 2);
            $table->decimal('retention_percent', 5, 2)->default(0);
            $table->string('tax_category', 2)->default('S');
            $table->decimal('tax_rate', 5, 2);
            $table->string('exempt_code', 40)->nullable();
            $table->string('exempt_reason', 255)->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->string('created_via', 20)->nullable();
            $table->foreignUuid('license_id')->nullable()->constrained('licenses')->nullOnDelete();
            $table->timestamps();

            $table->unique(['org_id', 'reference']);
            $table->index(['org_id', 'customer_id']);
            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();
            $table->foreign('customer_id')->references('id')->on('customers');
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('contract_stages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('contract_id');
            $table->unsignedSmallInteger('position');
            $table->string('name', 200);
            $table->string('name_ar', 200)->nullable();
            $table->decimal('percent', 7, 4)->nullable();
            $table->decimal('amount', 18, 2);
            $table->timestamps();

            $table->unique(['contract_id', 'position']);
            $table->foreign('contract_id')->references('id')->on('contracts')->cascadeOnDelete();
        });

        Schema::create('contract_invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('contract_id');
            $table->uuid('invoice_id');
            $table->uuid('stage_id')->nullable();
            $table->string('kind', 20);
            $table->decimal('stage_value', 18, 2)->default(0);
            $table->decimal('retention', 18, 2)->default(0);
            $table->decimal('released', 18, 2)->default(0);
            $table->timestamps();

            $table->unique('invoice_id');
            $table->index(['contract_id', 'kind']);
            $table->foreign('contract_id')->references('id')->on('contracts')->cascadeOnDelete();
            $table->foreign('invoice_id')->references('id')->on('invoices')->cascadeOnDelete();
            $table->foreign('stage_id')->references('id')->on('contract_stages');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_invoices');
        Schema::dropIfExists('contract_stages');
        Schema::dropIfExists('contracts');
    }
};
