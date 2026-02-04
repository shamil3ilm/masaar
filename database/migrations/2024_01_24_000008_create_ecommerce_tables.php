<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // E-commerce channels
        Schema::create('ecommerce_channels', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('platform', 30); // shopify, woocommerce, magento, custom, marketplace
            $table->string('platform_name')->nullable(); // Noon, Amazon, etc.
            $table->string('store_url')->nullable();
            $table->json('credentials')->nullable(); // Encrypted API keys
            $table->json('settings')->nullable();
            $table->foreignId('default_warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->foreignId('default_customer_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->boolean('sync_products')->default(true);
            $table->boolean('sync_orders')->default(true);
            $table->boolean('sync_inventory')->default(true);
            $table->boolean('auto_fulfill')->default(false);
            $table->timestamp('last_sync_at')->nullable();
            $table->string('status', 20)->default('active'); // active, paused, disconnected
            $table->timestamps();

            $table->index(['organization_id', 'status']);
        });

        // E-commerce orders
        Schema::create('ecommerce_orders', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('channel_id')->constrained('ecommerce_channels')->cascadeOnDelete();
            $table->string('external_order_id');
            $table->string('order_number');
            $table->string('status', 30); // pending, processing, shipped, delivered, cancelled, refunded
            $table->string('financial_status', 30)->nullable(); // pending, paid, partially_paid, refunded
            $table->string('fulfillment_status', 30)->nullable(); // unfulfilled, partial, fulfilled

            // Customer
            $table->string('customer_email')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();
            $table->foreignId('customer_id')->nullable()->constrained('contacts')->nullOnDelete();

            // Addresses
            $table->json('shipping_address')->nullable();
            $table->json('billing_address')->nullable();

            // Amounts
            $table->string('currency_code', 3);
            $table->decimal('subtotal', 15, 2);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('shipping_amount', 15, 2)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('total_amount', 15, 2);

            // Shipping
            $table->string('shipping_method')->nullable();
            $table->string('tracking_number')->nullable();
            $table->string('tracking_url')->nullable();

            // Processing
            $table->foreignId('sales_order_id')->nullable(); // Linked ERP sales order
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->boolean('is_processed')->default(false);
            $table->timestamp('processed_at')->nullable();

            $table->json('raw_data')->nullable(); // Original order data
            $table->text('notes')->nullable();
            $table->timestamp('ordered_at');
            $table->timestamps();

            $table->unique(['channel_id', 'external_order_id']);
            $table->index(['organization_id', 'status']);
            $table->index(['channel_id', 'ordered_at']);
        });

        // E-commerce order items
        Schema::create('ecommerce_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('ecommerce_orders')->cascadeOnDelete();
            $table->string('external_product_id')->nullable();
            $table->string('external_variant_id')->nullable();
            $table->string('sku')->nullable();
            $table->string('name');
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 15, 2);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('total_amount', 15, 2);
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->unsignedInteger('fulfilled_quantity')->default(0);
            $table->timestamps();
        });

        // Product mappings
        Schema::create('ecommerce_product_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_id')->constrained('ecommerce_channels')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('external_product_id');
            $table->string('external_variant_id')->nullable();
            $table->string('external_sku')->nullable();
            $table->boolean('sync_enabled')->default(true);
            $table->timestamp('last_sync_at')->nullable();
            $table->timestamps();

            $table->unique(['channel_id', 'external_product_id', 'external_variant_id'], 'ecom_product_mapping_unique');
            $table->index(['channel_id', 'product_id']);
        });

        // Sync logs
        Schema::create('ecommerce_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_id')->constrained('ecommerce_channels')->cascadeOnDelete();
            $table->string('sync_type', 30); // products, orders, inventory, customers
            $table->string('direction', 10); // push, pull
            $table->string('status', 20); // started, completed, failed
            $table->unsignedInteger('total_records')->default(0);
            $table->unsignedInteger('processed_records')->default(0);
            $table->unsignedInteger('failed_records')->default(0);
            $table->json('errors')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['channel_id', 'created_at']);
        });

        // Payment gateways
        Schema::create('payment_gateways', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('provider', 30); // stripe, paypal, tap, moyasar, hyperpay, mada
            $table->json('credentials')->nullable(); // Encrypted
            $table->json('settings')->nullable();
            $table->string('mode', 10)->default('test'); // test, live
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->json('supported_currencies')->nullable();
            $table->json('supported_methods')->nullable(); // card, mada, apple_pay, etc.
            $table->timestamps();

            $table->index(['organization_id', 'is_active']);
        });

        // Online payments
        Schema::create('online_payments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('gateway_id')->constrained('payment_gateways')->cascadeOnDelete();
            $table->morphs('payable'); // invoice, ecommerce_order, subscription
            $table->string('external_payment_id')->nullable(); // Gateway transaction ID
            $table->string('status', 20); // pending, authorized, captured, failed, refunded
            $table->string('currency_code', 3);
            $table->decimal('amount', 15, 2);
            $table->decimal('fee_amount', 15, 2)->default(0);
            $table->decimal('net_amount', 15, 2);
            $table->string('payment_method', 30)->nullable(); // card, mada, apple_pay
            $table->string('card_brand')->nullable();
            $table->string('card_last4', 4)->nullable();
            $table->json('gateway_response')->nullable();
            $table->text('failure_reason')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->foreignId('payment_received_id')->nullable(); // Linked to payments_received
            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index(['payable_type', 'payable_id']);
            $table->index('external_payment_id');
        });

        // QR Codes for invoices
        Schema::create('invoice_qr_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->string('qr_type', 30); // zatca, payment_link, custom
            $table->text('qr_data'); // Encoded data
            $table->string('qr_image_path')->nullable();
            $table->string('payment_link')->nullable();
            $table->decimal('payment_amount', 15, 2)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['invoice_id', 'qr_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_qr_codes');
        Schema::dropIfExists('online_payments');
        Schema::dropIfExists('payment_gateways');
        Schema::dropIfExists('ecommerce_sync_logs');
        Schema::dropIfExists('ecommerce_product_mappings');
        Schema::dropIfExists('ecommerce_order_items');
        Schema::dropIfExists('ecommerce_orders');
        Schema::dropIfExists('ecommerce_channels');
    }
};
