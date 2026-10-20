<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reverse charge on an expense: services from a supplier not resident where
 * the platform is taxed, on which the supplier charges no VAT and the
 * platform accounts for it itself.
 *
 * VAT Implementing Regulations Art 47(1): "Tax shall be paid by way of the
 * Reverse Charge Mechanism. The Taxable Customer must report the Output Tax
 * on the Supply and any Input Tax (to the extent that the Customer can
 * benefit from Input VAT deduction) in the Tax Return for that Tax Period."
 * So an expense keeps both: self_assessed_vat_tax is the output VAT, at
 * vat_rate on its amount in the tax currency; recoverable_vat_tax (already
 * there) is what of it the platform deducts as input VAT.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->decimal('vat_rate', 5, 2)->nullable();
            $table->decimal('self_assessed_vat_tax', 14, 2)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropColumn(['vat_rate', 'self_assessed_vat_tax']);
        });
    }
};
