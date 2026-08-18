<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds the signed_xml field to store the complete
     * signed invoice XML for ZATCA submission.
     */
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            // Signed XML - stored for audit and resubmission
            if (! Schema::hasColumn('invoices', 'signed_xml')) {
                $table->longText('signed_xml')->nullable()->after('qr_code');
            }

            // Document type for credit/debit notes
            if (! Schema::hasColumn('invoices', 'document_type')) {
                $table->string('document_type')->nullable()->after('type');
            }

            // Additional compliance fields
            if (! Schema::hasColumn('invoices', 'payment_means_code')) {
                $table->string('payment_means_code', 4)->nullable()->after('buyer_address');
            }
            if (! Schema::hasColumn('invoices', 'billing_reference_id')) {
                $table->string('billing_reference_id')->nullable()->after('payment_means_code');
            }
            if (! Schema::hasColumn('invoices', 'adjustment_reason')) {
                $table->string('adjustment_reason')->nullable()->after('billing_reference_id');
            }
            if (! Schema::hasColumn('invoices', 'discount_amount')) {
                $table->decimal('discount_amount', 12, 2)->default(0)->after('subtotal');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn([
                'signed_xml',
                'document_type',
                'payment_means_code',
                'billing_reference_id',
                'adjustment_reason',
                'discount_amount',
            ]);
        });
    }
};
