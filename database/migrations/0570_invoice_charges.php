<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Charges on an invoice: delivery, a service charge, packaging,
 * installation. On the whole invoice (BG-21) or on one line (BG-28, with
 * invoice_line_id).
 *
 * Each is an amount, or a percentage of a base (BT-101/BT-100, BT-143/
 * BT-142), with its reason in words and as a UNCL 7161 code (BT-104/BT-105,
 * BT-144/BT-145). amount is as entered - with VAT in it on an invoice priced
 * that way - and net_amount is what the document states (zero for a line
 * charge on such an invoice, which the document folds into the line's net
 * and does not state apart). A document charge
 * is made at its own tax category and rate (BT-102, BT-103), with the
 * exemption it falls under when it is not standard-rated; a line charge is
 * taxed as its line is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_charges', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->foreignUuid('invoice_line_id')->nullable()->constrained('invoice_lines')->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('reason', 255);
            $table->string('reason_code', 3);
            $table->decimal('percent', 5, 2)->nullable();
            $table->decimal('base_amount', 14, 2)->nullable();
            $table->decimal('amount', 14, 2);
            $table->decimal('net_amount', 14, 2);
            $table->string('tax_category', 2)->nullable();
            $table->decimal('tax_rate', 5, 2)->nullable();
            $table->string('exempt_code', 50)->nullable();
            $table->string('exempt_reason', 255)->nullable();
            $table->timestamps();

            $table->index(['invoice_id', 'invoice_line_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_charges');
    }
};
