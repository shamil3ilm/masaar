<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();

            // Invoice identification
            $table->string('invoice_number');
            $table->string('type');      // standard (B2B), simplified (B2C)
            $table->string('status');    // draft, issued, submitted, accepted, rejected

            // Dates
            $table->date('issue_date');
            $table->date('supply_date')->nullable();

            // Buyer info
            $table->string('currency', 3)->default('SAR');
            $table->string('buyer_name');
            $table->string('buyer_vat_number')->nullable();
            $table->text('buyer_address')->nullable();

            // Amounts
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);

            // ZATCA compliance fields
            $table->string('hash')->nullable();           // Invoice hash for compliance
            $table->text('qr_code')->nullable();          // Base64 QR code
            $table->json('zatca_response')->nullable();   // ZATCA API response

            $table->text('notes')->nullable();
            $table->timestamps();

            // Indexes
            $table->index(['organization_id', 'status']);
            $table->index('invoice_number');
        });

        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('invoice_id')->constrained()->cascadeOnDelete();

            $table->string('description');
            $table->decimal('quantity', 12, 3);
            $table->decimal('unit_price', 12, 2);
            $table->decimal('tax_rate', 5, 2)->default(15.00); // Default VAT 15%
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('line_total', 12, 2)->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_lines');
        Schema::dropIfExists('invoices');
    }
};
