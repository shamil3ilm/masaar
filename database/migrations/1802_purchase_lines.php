<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One line of a purchase invoice: what was bought, its VAT treatment -
 * standard, zero, exempt, out of scope, an import with VAT paid at customs,
 * or services from abroad under the reverse charge - whether it is a capital
 * asset, and its amounts in the currency billed and in the tax currency.
 *
 * For a reverse-charge line the VAT is the organization's own, assessed at
 * the rate shown (KSA VAT Implementing Regulations Art 47; UAE Federal
 * Decree-Law No. 8 of 2017 Art 48). Written with its invoice and never
 * changed (class c).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('purchase_invoice_id');
            $table->unsignedSmallInteger('position');
            $table->string('description', 500);
            $table->string('treatment', 20);
            $table->decimal('vat_rate', 5, 2)->default(0);
            $table->decimal('net', 14, 2);
            $table->decimal('vat', 14, 2);
            $table->decimal('net_tax', 14, 2);
            $table->decimal('vat_tax', 14, 2);
            $table->boolean('is_capital_asset')->default(false);
            $table->timestamp('created_at')->nullable();

            $table->index('purchase_invoice_id');
            $table->foreign('purchase_invoice_id')->references('id')->on('purchase_invoices');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_lines');
    }
};
