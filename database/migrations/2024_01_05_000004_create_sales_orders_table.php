<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Sales Orders
        Schema::create('sales_orders', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();

            $table->string('order_number', 50);
            $table->foreignId('quotation_id')->nullable()->constrained('quotations')->nullOnDelete();

            // Customer info
            $table->foreignId('customer_id')->constrained('contacts');
            $table->string('customer_name', 200);
            $table->string('customer_email', 100)->nullable();
            $table->text('billing_address')->nullable();
            $table->text('shipping_address')->nullable();

            // Dates
            $table->date('order_date');
            $table->date('expected_delivery_date')->nullable();
            $table->date('delivery_date')->nullable();

            // Currency
            $table->string('currency_code', 3)->default('SAR');
            $table->decimal('exchange_rate', 18, 8)->default(1);

            // Amounts
            $table->decimal('subtotal', 18, 4)->default(0);
            $table->enum('discount_type', ['percentage', 'fixed'])->nullable();
            $table->decimal('discount_value', 18, 4)->default(0);
            $table->decimal('discount_amount', 18, 4)->default(0);
            $table->decimal('tax_amount', 18, 4)->default(0);
            $table->decimal('total', 18, 4)->default(0);

            // Status
            $table->enum('status', [
                'draft',
                'confirmed',
                'processing',
                'partially_delivered',
                'delivered',
                'invoiced',
                'cancelled',
            ])->default('draft');

            $table->foreignId('salesperson_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete(); // Default fulfillment warehouse
            $table->text('notes')->nullable();
            $table->text('delivery_instructions')->nullable();
            $table->string('reference', 100)->nullable(); // Customer PO number

            $table->unsignedInteger('version')->default(1);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'order_number']);
            $table->index(['organization_id', 'customer_id']);
            $table->index(['organization_id', 'status']);
        });

        // Sales Order Lines
        Schema::create('sales_order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_order_id')->constrained()->cascadeOnDelete();

            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->text('description');

            $table->decimal('quantity', 18, 4);
            $table->decimal('quantity_delivered', 18, 4)->default(0);
            $table->decimal('quantity_invoiced', 18, 4)->default(0);
            $table->foreignId('unit_id')->nullable()->constrained('units_of_measure')->nullOnDelete();
            $table->decimal('unit_price', 18, 4);

            $table->enum('discount_type', ['percentage', 'fixed'])->nullable();
            $table->decimal('discount_value', 18, 4)->default(0);
            $table->decimal('discount_amount', 18, 4)->default(0);

            $table->foreignId('tax_category_id')->nullable()->constrained('tax_categories')->nullOnDelete();
            $table->decimal('tax_rate', 8, 4)->default(0);
            $table->decimal('tax_amount', 18, 4)->default(0);

            $table->decimal('subtotal', 18, 4)->default(0);
            $table->decimal('total', 18, 4)->default(0);

            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('line_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_order_lines');
        Schema::dropIfExists('sales_orders');
    }
};
