<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            // Identification
            $table->string('sku', 50);
            $table->string('barcode', 50)->nullable();
            $table->string('name', 200);
            $table->text('description')->nullable();

            // Classification
            $table->enum('type', ['goods', 'service'])->default('goods');
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('unit_id')->constrained('units_of_measure');

            // Pricing
            $table->decimal('purchase_price', 18, 4)->default(0);
            $table->decimal('selling_price', 18, 4)->default(0);
            $table->decimal('minimum_price', 18, 4)->nullable(); // Floor price

            // Tax
            $table->foreignId('tax_category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('hsn_code', 20)->nullable(); // HSN/SAC for India GST

            // Accounting Links
            $table->foreignId('income_account_id')->nullable()->constrained('chart_of_accounts')->nullOnDelete();
            $table->foreignId('expense_account_id')->nullable()->constrained('chart_of_accounts')->nullOnDelete();
            $table->foreignId('inventory_account_id')->nullable()->constrained('chart_of_accounts')->nullOnDelete();

            // Inventory Settings
            $table->enum('costing_method', ['fifo', 'weighted_average', 'standard'])->default('weighted_average');
            $table->boolean('track_inventory')->default(true);
            $table->decimal('reorder_level', 18, 4)->nullable();
            $table->decimal('reorder_quantity', 18, 4)->nullable();

            // Physical Properties
            $table->decimal('weight', 10, 3)->nullable();
            $table->string('weight_unit', 10)->nullable();
            $table->decimal('length', 10, 3)->nullable();
            $table->decimal('width', 10, 3)->nullable();
            $table->decimal('height', 10, 3)->nullable();
            $table->string('dimension_unit', 10)->nullable();

            // Media
            $table->string('image_url', 500)->nullable();
            $table->json('gallery_urls')->nullable();

            // Status
            $table->boolean('is_active')->default(true);
            $table->boolean('is_purchasable')->default(true);
            $table->boolean('is_sellable')->default(true);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'sku']);
            $table->index(['organization_id', 'barcode']);
            $table->index(['organization_id', 'category_id']);
            $table->index(['organization_id', 'is_active']);
        });

        // Product variants (for products with variations like size, color)
        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('sku', 50);
            $table->string('barcode', 50)->nullable();
            $table->string('name', 200);
            $table->json('attributes'); // e.g., {"size": "XL", "color": "Red"}
            $table->decimal('purchase_price', 18, 4)->nullable(); // Override parent
            $table->decimal('selling_price', 18, 4)->nullable(); // Override parent
            $table->string('image_url', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['product_id', 'sku']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('products');
    }
};
