<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The prepayment invoices (type 386) a final invoice settles, one row per
 * tax category each was prepaid at: which invoice, its number and when it
 * was issued (KSA-26 to KSA-29), and what was prepaid in that category
 * (KSA-31 to KSA-34, and the exemption KSA-41 and KSA-42 where it is not
 * standard-rated). Copied when the final invoice is drafted, so the final
 * document says what the prepayment said.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_prepayments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->foreignUuid('prepayment_invoice_id')->constrained('invoices');
            $table->unsignedSmallInteger('position');
            $table->string('invoice_number', 255);
            $table->date('issue_date');
            $table->timestamp('issued_at');
            $table->string('tax_category', 2);
            $table->decimal('tax_rate', 5, 2);
            $table->string('exempt_code', 50)->nullable();
            $table->string('exempt_reason', 255)->nullable();
            $table->decimal('taxable_amount', 14, 2);
            $table->decimal('tax_amount', 14, 2);
            $table->timestamps();

            $table->index('prepayment_invoice_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_prepayments');
    }
};
