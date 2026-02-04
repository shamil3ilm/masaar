<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Customer groups (Retail, Wholesale, VIP, Distributor, etc.)
        Schema::create('customer_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('code', 20);
            $table->text('description')->nullable();
            $table->decimal('default_discount_percent', 5, 2)->default(0);
            $table->decimal('credit_limit', 15, 4)->nullable();
            $table->unsignedSmallInteger('payment_terms_days')->default(0); // 0 = cash
            $table->boolean('tax_exempt')->default(false);
            $table->boolean('wholesale')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('priority')->default(0); // Higher = better pricing
            $table->timestamps();

            $table->unique(['organization_id', 'code']);
        });

        // Price lists (can be for specific customer groups, date ranges, etc.)
        Schema::create('price_lists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('code', 20);
            $table->text('description')->nullable();
            $table->string('type', 20)->default('selling'); // selling, buying
            $table->string('currency_code', 3);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_tax_inclusive')->default(false);
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->foreignId('customer_group_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('priority')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'code']);
            $table->index(['organization_id', 'type', 'is_active']);
        });

        // Price list items (prices for products in a price list)
        Schema::create('price_list_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('price_list_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->decimal('unit_price', 15, 4);
            $table->decimal('min_quantity', 15, 4)->default(1); // For bulk pricing tiers
            $table->decimal('max_quantity', 15, 4)->nullable();
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->timestamps();

            $table->unique(['price_list_id', 'product_id', 'min_quantity']);
            $table->index(['product_id', 'min_quantity']);
        });

        // Customer-specific pricing (overrides price lists)
        Schema::create('customer_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete(); // Customer
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->decimal('unit_price', 15, 4);
            $table->decimal('min_quantity', 15, 4)->default(1);
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['contact_id', 'product_id', 'min_quantity']);
        });

        // Bulk pricing tiers (quantity-based pricing)
        Schema::create('bulk_pricing_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('categories')->cascadeOnDelete();
            $table->string('name', 100);
            $table->decimal('min_quantity', 15, 4);
            $table->decimal('max_quantity', 15, 4)->nullable();
            $table->string('discount_type', 20); // percent, fixed_amount, fixed_price
            $table->decimal('discount_value', 15, 4);
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['organization_id', 'product_id', 'is_active']);
            $table->index(['organization_id', 'category_id', 'is_active']);
        });

        // Add customer_group_id to contacts table
        Schema::table('contacts', function (Blueprint $table) {
            $table->foreignId('customer_group_id')->nullable()->after('contact_type')
                ->constrained()->nullOnDelete();
            $table->foreignId('default_price_list_id')->nullable()->after('customer_group_id')
                ->constrained('price_lists')->nullOnDelete();
            $table->boolean('tax_exempt')->default(false)->after('default_price_list_id');
            $table->string('tax_exemption_number', 50)->nullable()->after('tax_exempt');
            $table->date('tax_exemption_expiry')->nullable()->after('tax_exemption_number');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropForeign(['customer_group_id']);
            $table->dropForeign(['default_price_list_id']);
            $table->dropColumn([
                'customer_group_id',
                'default_price_list_id',
                'tax_exempt',
                'tax_exemption_number',
                'tax_exemption_expiry',
            ]);
        });

        Schema::dropIfExists('bulk_pricing_rules');
        Schema::dropIfExists('customer_prices');
        Schema::dropIfExists('price_list_items');
        Schema::dropIfExists('price_lists');
        Schema::dropIfExists('customer_groups');
    }
};
