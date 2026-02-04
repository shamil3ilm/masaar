<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            // Contact type
            $table->enum('contact_type', ['customer', 'supplier', 'both'])->default('customer');

            // Basic info
            $table->string('company_name', 200)->nullable();
            $table->string('contact_name', 100);
            $table->string('email', 100)->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('mobile', 20)->nullable();
            $table->string('website', 255)->nullable();

            // Tax info
            $table->string('tax_number', 50)->nullable(); // TRN for GCC, GSTIN for India
            $table->string('tax_registration_name', 200)->nullable();

            // Financial terms
            $table->integer('payment_terms')->default(30); // Days
            $table->decimal('credit_limit', 18, 4)->default(0);
            $table->string('currency_code', 3)->default('SAR');

            // Linked accounts
            $table->foreignId('receivable_account_id')->nullable()->constrained('chart_of_accounts')->nullOnDelete();
            $table->foreignId('payable_account_id')->nullable()->constrained('chart_of_accounts')->nullOnDelete();

            // Billing address
            $table->string('billing_address_line_1', 255)->nullable();
            $table->string('billing_address_line_2', 255)->nullable();
            $table->string('billing_city', 100)->nullable();
            $table->string('billing_state', 100)->nullable();
            $table->string('billing_postal_code', 20)->nullable();
            $table->string('billing_country_code', 2)->nullable();

            // Shipping address
            $table->string('shipping_address_line_1', 255)->nullable();
            $table->string('shipping_address_line_2', 255)->nullable();
            $table->string('shipping_city', 100)->nullable();
            $table->string('shipping_state', 100)->nullable();
            $table->string('shipping_postal_code', 20)->nullable();
            $table->string('shipping_country_code', 2)->nullable();

            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'contact_type']);
            $table->index(['organization_id', 'company_name']);
            $table->index('tax_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contacts');
    }
};
