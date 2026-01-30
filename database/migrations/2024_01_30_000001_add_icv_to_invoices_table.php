<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            // Invoice Counter Value - sequential per organization
            $table->unsignedBigInteger('icv')->nullable()->after('qr_code');

            // Document type for credit/debit notes
            $table->string('document_type')->nullable()->after('type');

            // Payment means code
            $table->string('payment_means_code', 10)->nullable()->after('buyer_address');

            // Billing reference for credit/debit notes
            $table->string('billing_reference_id')->nullable()->after('payment_means_code');

            // Index for ICV uniqueness per organization
            $table->unique(['organization_id', 'icv'], 'invoices_org_icv_unique');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropUnique('invoices_org_icv_unique');
            $table->dropColumn(['icv', 'document_type', 'payment_means_code', 'billing_reference_id']);
        });
    }
};
