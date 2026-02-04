<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Promotions/Campaigns
        Schema::create('promotions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('code', 30)->nullable(); // Promo code for manual entry
            $table->text('description')->nullable();
            $table->string('type', 30);
            // Types: percentage, fixed_amount, fixed_price, buy_x_get_y, bundle, tiered

            $table->string('apply_to', 20)->default('line'); // line, order, shipping
            $table->string('target', 30)->default('all');
            // Targets: all, specific_products, specific_categories, specific_customers, customer_groups

            // Discount value
            $table->decimal('discount_value', 15, 4)->nullable();
            $table->decimal('max_discount_amount', 15, 4)->nullable(); // Cap for percentage discounts

            // Buy X Get Y configuration
            $table->unsignedInteger('buy_quantity')->nullable();
            $table->unsignedInteger('get_quantity')->nullable();
            $table->decimal('get_discount_percent', 5, 2)->nullable(); // 100 = free

            // Tiered discount configuration (JSON)
            $table->json('tiers')->nullable();
            // [{ min_quantity: 10, discount_percent: 5 }, { min_quantity: 50, discount_percent: 10 }]

            // Conditions
            $table->decimal('min_order_amount', 15, 4)->nullable();
            $table->decimal('min_quantity', 15, 4)->nullable();
            $table->unsignedInteger('max_uses')->nullable();
            $table->unsignedInteger('max_uses_per_customer')->nullable();
            $table->unsignedInteger('current_uses')->default(0);

            // Validity
            $table->datetime('start_date');
            $table->datetime('end_date')->nullable();
            $table->json('valid_days')->nullable(); // [0,1,2,3,4,5,6] days of week
            $table->time('valid_time_start')->nullable();
            $table->time('valid_time_end')->nullable();

            // Stacking
            $table->boolean('is_stackable')->default(false); // Can combine with other promotions
            $table->boolean('is_exclusive')->default(false); // Only one exclusive promo per order
            $table->unsignedSmallInteger('priority')->default(0);

            // Status
            $table->boolean('is_active')->default(true);
            $table->boolean('requires_code')->default(false);

            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'code']);
            $table->index(['organization_id', 'is_active', 'start_date', 'end_date']);
        });

        // Promotion products (which products the promotion applies to)
        Schema::create('promotion_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promotion_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('categories')->cascadeOnDelete();
            $table->boolean('is_excluded')->default(false); // true = exclude this product/category

            $table->index(['promotion_id', 'product_id']);
            $table->index(['promotion_id', 'category_id']);
        });

        // Promotion customers (which customers can use the promotion)
        Schema::create('promotion_customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promotion_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('customer_group_id')->nullable()->constrained()->cascadeOnDelete();
            $table->boolean('is_excluded')->default(false);

            $table->index(['promotion_id', 'contact_id']);
            $table->index(['promotion_id', 'customer_group_id']);
        });

        // Promotion usage tracking
        Schema::create('promotion_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promotion_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('order_type', 50); // Invoice, SalesOrder
            $table->unsignedBigInteger('order_id');
            $table->decimal('discount_amount', 15, 4);
            $table->timestamps();

            $table->index(['promotion_id', 'contact_id']);
            $table->index(['order_type', 'order_id']);
        });

        // Coupon codes (for shareable/unique codes)
        Schema::create('coupon_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promotion_id')->constrained()->cascadeOnDelete();
            $table->string('code', 30)->unique();
            $table->unsignedInteger('max_uses')->nullable();
            $table->unsignedInteger('current_uses')->default(0);
            $table->foreignId('assigned_to')->nullable()->constrained('contacts')->nullOnDelete();
            $table->datetime('expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['code', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_codes');
        Schema::dropIfExists('promotion_usages');
        Schema::dropIfExists('promotion_customers');
        Schema::dropIfExists('promotion_products');
        Schema::dropIfExists('promotions');
    }
};
