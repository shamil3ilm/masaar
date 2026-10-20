<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a customer owes the platform: one row per invoice the billing
 * organization issued it, in the customer's currency, and what has been
 * paid, credited and written off against it.
 *
 * invoice_id is the tax invoice itself, issued through the billing
 * organization's country's module; the amounts are copied from it so a
 * balance or an ageing is one query. vat_amount_tax is its VAT in the tax
 * currency at exchange_rate, as the invoice states it (ZATCA XML
 * Implementation Standard BR-53 and BR-KSA-EN16931-02: BT-111 in SAR).
 *
 * status is open, partly_paid, paid, overdue or written_off, kept with the
 * sums that decide it so a list can filter on it.
 *
 * A financial record (class c): never deleted, its status moving as it is
 * paid. Who issued it, and who wrote it off.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receivables', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // The customer that owes it.
            $table->uuid('org_id');
            $table->uuid('invoice_id');
            // The plan it bills, when it bills one.
            $table->uuid('license_id')->nullable();
            $table->string('description', 500);
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->string('currency', 3);
            $table->decimal('subtotal', 14, 2);
            $table->decimal('vat_amount', 14, 2);
            $table->decimal('total', 14, 2);
            $table->string('tax_currency', 3);
            // Units of the tax currency for one of currency; null when they
            // are the same. Where it came from: entered, or the rate table's
            // day.
            $table->decimal('exchange_rate', 18, 6)->nullable();
            $table->string('rate_source', 10)->nullable();
            $table->date('rate_date')->nullable();
            $table->decimal('vat_amount_tax', 14, 2);
            $table->date('issue_date');
            $table->date('due_date');
            $table->string('status', 16);
            $table->decimal('amount_paid', 14, 2)->default(0);
            $table->decimal('amount_credited', 14, 2)->default(0);
            $table->decimal('amount_written_off', 14, 2)->default(0);
            $table->date('paid_on')->nullable();
            $table->timestamp('written_off_at')->nullable();
            $table->uuid('written_off_by')->nullable();
            $table->string('write_off_reason', 500)->nullable();
            $table->uuid('created_by')->nullable();
            $table->string('created_via', 20)->nullable();
            $table->timestamps();

            $table->unique('invoice_id');
            $table->index(['org_id', 'status']);
            $table->index(['status', 'due_date']);
            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('invoice_id')->references('id')->on('invoices');
            $table->foreign('license_id')->references('id')->on('licenses')->nullOnDelete();

            foreach (['created_by', 'written_off_by'] as $actor) {
                $table->foreign($actor)->references('id')->on('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receivables');
    }
};
