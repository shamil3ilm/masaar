<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_lines', function (Blueprint $table) {
            // Tax category code (S=Standard, Z=Zero-rated, E=Exempt, O=Out of scope)
            $table->char('tax_category', 1)->default('S')->after('tax_amount');

            // ZATCA exemption reason code (e.g., VATEX-SA-29-7, VATEX-SA-HEA)
            $table->string('tax_exemption_code', 50)->nullable()->after('tax_category');

            // Human-readable exemption reason
            $table->string('tax_exemption_reason', 255)->nullable()->after('tax_exemption_code');

            // Unit code (UN/ECE Rec 20 codes: PCE, KGM, MTR, LTR, etc.)
            $table->string('unit_code', 10)->default('PCE')->after('quantity');

            // Item classification code (e.g., UNSPSC, GPC)
            $table->string('item_classification_code', 50)->nullable()->after('description');
        });

        Schema::table('invoices', function (Blueprint $table) {
            // Discount amount (AllowanceCharge)
            $table->decimal('discount_amount', 15, 2)->default(0)->after('subtotal');

            // Reason for credit/debit note
            $table->string('adjustment_reason', 255)->nullable()->after('billing_reference_id');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->dropColumn([
                'tax_category',
                'tax_exemption_code',
                'tax_exemption_reason',
                'unit_code',
                'item_classification_code',
            ]);
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['discount_amount', 'adjustment_reason']);
        });
    }
};
