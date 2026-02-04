<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Purchase Orders
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();

            $table->string('order_number', 50);

            // Supplier info
            $table->foreignId('supplier_id')->constrained('contacts');
            $table->string('supplier_name', 200);
            $table->string('supplier_email', 100)->nullable();
            $table->text('supplier_address')->nullable();

            // Delivery info
            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();
            $table->text('delivery_address')->nullable();

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
                'sent',
                'confirmed',
                'partially_received',
                'received',
                'billed',
                'cancelled',
            ])->default('draft');

            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();

            $table->text('notes')->nullable();
            $table->text('terms_and_conditions')->nullable();
            $table->string('reference', 100)->nullable(); // Supplier quote reference

            $table->unsignedInteger('version')->default(1);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'order_number']);
            $table->index(['organization_id', 'supplier_id']);
            $table->index(['organization_id', 'status']);
        });

        // Purchase Order Lines
        Schema::create('purchase_order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();

            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->text('description');

            $table->decimal('quantity', 18, 4);
            $table->decimal('quantity_received', 18, 4)->default(0);
            $table->decimal('quantity_billed', 18, 4)->default(0);
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
        Schema::dropIfExists('purchase_order_lines');
        Schema::dropIfExists('purchase_orders');
    }
};
