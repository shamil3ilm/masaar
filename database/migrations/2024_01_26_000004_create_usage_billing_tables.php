<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Subscription plans
        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('code', 30)->unique();
            $table->text('description')->nullable();
            $table->string('tier', 20); // free, starter, professional, enterprise
            $table->string('billing_cycle', 20); // monthly, yearly, one_time
            $table->decimal('base_price', 15, 2);
            $table->string('currency_code', 3)->default('USD');

            // Limits
            $table->unsignedInteger('max_users')->nullable(); // NULL = unlimited
            $table->unsignedInteger('max_branches')->nullable();
            $table->unsignedBigInteger('storage_limit_mb')->nullable();
            $table->unsignedInteger('max_invoices_per_month')->nullable();
            $table->unsignedInteger('max_products')->nullable();
            $table->unsignedInteger('max_customers')->nullable();
            $table->unsignedInteger('max_employees')->nullable();
            $table->unsignedInteger('api_calls_per_month')->nullable();

            // Features
            $table->json('included_modules'); // ['sales', 'purchase', 'inventory', 'accounting', 'hr', 'crm', 'manufacturing']
            $table->json('features')->nullable(); // Additional feature flags

            // Trial
            $table->unsignedSmallInteger('trial_days')->default(0);
            $table->boolean('trial_requires_card')->default(false);

            // Settings
            $table->boolean('is_public')->default(true); // Visible on pricing page
            $table->boolean('is_popular')->default(false); // Highlight as popular
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tier', 'is_active']);
        });

        // Plan add-ons (additional purchasable features/limits)
        Schema::create('subscription_addons', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('code', 30)->unique();
            $table->text('description')->nullable();
            $table->string('addon_type', 30); // users, storage, api_calls, feature, module
            $table->decimal('price', 15, 2);
            $table->string('pricing_model', 20); // flat, per_unit, tiered
            $table->string('billing_cycle', 20); // monthly, yearly, one_time
            $table->unsignedInteger('unit_quantity')->nullable(); // e.g., 5 users, 10GB storage
            $table->string('unit_label')->nullable(); // "users", "GB", etc.
            $table->json('compatible_plans')->nullable(); // Plan IDs this addon works with
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['addon_type', 'is_active']);
        });

        // Organization subscriptions
        Schema::create('organization_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained('subscription_plans')->cascadeOnDelete();
            $table->string('status', 30)->default('active'); // trial, active, past_due, cancelled, expired, suspended

            // Billing period
            $table->date('starts_at');
            $table->date('ends_at')->nullable();
            $table->date('trial_ends_at')->nullable();
            $table->date('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();

            // Pricing at subscription time (prices can change)
            $table->decimal('base_price', 15, 2);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->string('discount_code')->nullable();

            // Current limits (can be overridden from plan)
            $table->unsignedInteger('max_users')->nullable();
            $table->unsignedInteger('max_branches')->nullable();
            $table->unsignedBigInteger('storage_limit_mb')->nullable();
            $table->unsignedInteger('max_invoices_per_month')->nullable();
            $table->json('enabled_modules')->nullable();
            $table->json('enabled_features')->nullable();

            // Billing
            $table->boolean('auto_renew')->default(true);
            $table->string('payment_method_id')->nullable();
            $table->date('next_billing_date')->nullable();

            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index(['status', 'ends_at']);
        });

        // Subscription add-ons purchased
        Schema::create('subscription_addon_purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained('organization_subscriptions')->cascadeOnDelete();
            $table->foreignId('addon_id')->constrained('subscription_addons')->cascadeOnDelete();
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('unit_price', 15, 2);
            $table->decimal('total_price', 15, 2);
            $table->date('starts_at');
            $table->date('ends_at')->nullable();
            $table->string('status', 20)->default('active'); // active, cancelled, expired
            $table->timestamps();

            $table->index(['subscription_id', 'status']);
        });

        // Usage metrics (tracking all usage for metered billing)
        Schema::create('usage_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('metric_type', 50); // api_calls, storage_mb, invoices_created, users_active, sms_sent, emails_sent
            $table->unsignedBigInteger('quantity');
            $table->date('metric_date');
            $table->string('billing_period', 7)->nullable(); // 2024-01 format
            $table->timestamps();

            $table->unique(['organization_id', 'metric_type', 'metric_date']);
            $table->index(['organization_id', 'billing_period']);
        });

        // Usage aggregates (daily/monthly rollups)
        Schema::create('usage_aggregates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('metric_type', 50);
            $table->string('period_type', 10); // daily, monthly
            $table->string('period', 10); // 2024-01-15 or 2024-01
            $table->unsignedBigInteger('total_quantity');
            $table->unsignedBigInteger('peak_quantity')->nullable();
            $table->decimal('average_quantity', 15, 2)->nullable();
            $table->json('breakdown')->nullable(); // Detailed breakdown by feature/endpoint
            $table->timestamps();

            $table->unique(['organization_id', 'metric_type', 'period_type', 'period']);
            $table->index(['organization_id', 'period_type', 'period']);
        });

        // Real-time usage snapshots (current usage vs limits)
        Schema::create('usage_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('users_count')->default(0);
            $table->unsignedInteger('branches_count')->default(0);
            $table->unsignedBigInteger('storage_used_mb')->default(0);
            $table->unsignedInteger('invoices_this_month')->default(0);
            $table->unsignedInteger('products_count')->default(0);
            $table->unsignedInteger('customers_count')->default(0);
            $table->unsignedInteger('employees_count')->default(0);
            $table->unsignedBigInteger('api_calls_this_month')->default(0);
            $table->timestamp('snapshot_at');
            $table->timestamps();

            $table->unique(['organization_id']);
        });

        // Billing invoices (platform invoices to organizations)
        Schema::create('billing_invoices', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('invoice_number', 30)->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained('organization_subscriptions')->nullOnDelete();

            // Billing period
            $table->date('billing_period_start');
            $table->date('billing_period_end');
            $table->date('invoice_date');
            $table->date('due_date');

            // Amounts
            $table->string('currency_code', 3)->default('USD');
            $table->decimal('subtotal', 15, 2);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('total', 15, 2);
            $table->decimal('amount_paid', 15, 2)->default(0);
            $table->decimal('amount_due', 15, 2);

            // Status
            $table->string('status', 20)->default('draft'); // draft, sent, paid, partial, overdue, cancelled, refunded
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('paid_at')->nullable();

            // PDF
            $table->string('pdf_path')->nullable();

            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'status']);
            $table->index(['status', 'due_date']);
        });

        // Billing invoice line items
        Schema::create('billing_invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('billing_invoices')->cascadeOnDelete();
            $table->string('item_type', 30); // subscription, addon, overage, credit, adjustment
            $table->string('description');
            $table->decimal('quantity', 15, 4)->default(1);
            $table->string('unit_label')->nullable();
            $table->decimal('unit_price', 15, 4);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('total', 15, 2);
            $table->foreignId('plan_id')->nullable()->constrained('subscription_plans')->nullOnDelete();
            $table->foreignId('addon_id')->nullable()->constrained('subscription_addons')->nullOnDelete();
            $table->string('metric_type')->nullable(); // For usage-based items
            $table->unsignedSmallInteger('line_order')->default(0);
            $table->timestamps();

            $table->index(['invoice_id', 'line_order']);
        });

        // Payment methods (stored for recurring billing)
        Schema::create('billing_payment_methods', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30); // card, bank_transfer, wallet
            $table->string('provider', 30); // stripe, paypal, razorpay
            $table->string('provider_payment_method_id')->nullable(); // External ID
            $table->string('card_brand', 20)->nullable();
            $table->string('card_last_four', 4)->nullable();
            $table->unsignedSmallInteger('card_exp_month')->nullable();
            $table->unsignedSmallInteger('card_exp_year')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('bank_account_last_four', 4)->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'is_default']);
        });

        // Payment transactions
        Schema::create('billing_payments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('transaction_id', 100)->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained('billing_invoices')->nullOnDelete();
            $table->foreignId('payment_method_id')->nullable()->constrained('billing_payment_methods')->nullOnDelete();

            // Payment details
            $table->decimal('amount', 15, 2);
            $table->string('currency_code', 3);
            $table->string('payment_type', 30); // subscription, addon, overage, credit_purchase
            $table->string('provider', 30); // stripe, paypal, manual
            $table->string('provider_transaction_id')->nullable();
            $table->string('status', 20)->default('pending'); // pending, processing, completed, failed, refunded

            // Status tracking
            $table->timestamp('processed_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->string('failure_code')->nullable();

            // Refund info
            $table->boolean('is_refunded')->default(false);
            $table->decimal('refunded_amount', 15, 2)->default(0);
            $table->timestamp('refunded_at')->nullable();
            $table->text('refund_reason')->nullable();

            $table->json('provider_response')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index(['provider_transaction_id']);
        });

        // Credits/wallet for organizations
        Schema::create('billing_credits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->decimal('balance', 15, 2)->default(0);
            $table->decimal('total_credited', 15, 2)->default(0);
            $table->decimal('total_used', 15, 2)->default(0);
            $table->string('currency_code', 3)->default('USD');
            $table->timestamps();

            $table->unique(['organization_id']);
        });

        // Credit transactions
        Schema::create('billing_credit_transactions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('transaction_type', 30); // purchase, bonus, referral, applied, expired, refund
            $table->decimal('amount', 15, 2);
            $table->decimal('balance_before', 15, 2);
            $table->decimal('balance_after', 15, 2);
            $table->text('description')->nullable();
            $table->foreignId('invoice_id')->nullable()->constrained('billing_invoices')->nullOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained('billing_payments')->nullOnDelete();
            $table->date('expires_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('platform_admins')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'created_at']);
        });

        // Usage alerts (notify when approaching limits)
        Schema::create('usage_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('metric_type', 50);
            $table->unsignedTinyInteger('threshold_percent'); // 80, 90, 100
            $table->unsignedBigInteger('threshold_value');
            $table->unsignedBigInteger('current_value');
            $table->string('status', 20)->default('triggered'); // triggered, notified, resolved
            $table->timestamp('notified_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
        });

        // Metered pricing tiers (for usage-based billing)
        Schema::create('metered_pricing_tiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained('subscription_plans')->cascadeOnDelete();
            $table->string('metric_type', 50);
            $table->unsignedBigInteger('from_quantity');
            $table->unsignedBigInteger('to_quantity')->nullable(); // NULL = unlimited
            $table->decimal('price_per_unit', 15, 6);
            $table->string('unit_label', 50);
            $table->timestamps();

            $table->index(['plan_id', 'metric_type']);
        });

        // Discount codes/coupons
        Schema::create('discount_codes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('code', 50)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('discount_type', 20); // percentage, fixed_amount
            $table->decimal('discount_value', 15, 2);
            $table->decimal('max_discount_amount', 15, 2)->nullable(); // Cap for percentage discounts
            $table->decimal('min_order_amount', 15, 2)->nullable();
            $table->string('applies_to', 30)->default('all'); // all, specific_plans, addons
            $table->json('applicable_plan_ids')->nullable();
            $table->unsignedInteger('max_uses')->nullable();
            $table->unsignedInteger('max_uses_per_org')->default(1);
            $table->unsignedInteger('times_used')->default(0);
            $table->date('starts_at');
            $table->date('expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->constrained('platform_admins')->cascadeOnDelete();
            $table->timestamps();

            $table->index(['code', 'is_active']);
        });

        // Discount code usage history
        Schema::create('discount_code_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('discount_code_id')->constrained('discount_codes')->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained('billing_invoices')->nullOnDelete();
            $table->decimal('discount_amount', 15, 2);
            $table->timestamps();

            $table->index(['discount_code_id', 'organization_id']);
        });

        // API request logs (for API call metering)
        Schema::create('api_request_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('endpoint', 255);
            $table->string('method', 10);
            $table->unsignedSmallInteger('response_status');
            $table->unsignedInteger('response_time_ms');
            $table->string('ip_address', 45);
            $table->string('api_version', 10)->nullable();
            $table->timestamp('requested_at');
            $table->timestamps();

            $table->index(['organization_id', 'requested_at']);
            $table->index(['requested_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_request_logs');
        Schema::dropIfExists('discount_code_usages');
        Schema::dropIfExists('discount_codes');
        Schema::dropIfExists('metered_pricing_tiers');
        Schema::dropIfExists('usage_alerts');
        Schema::dropIfExists('billing_credit_transactions');
        Schema::dropIfExists('billing_credits');
        Schema::dropIfExists('billing_payments');
        Schema::dropIfExists('billing_payment_methods');
        Schema::dropIfExists('billing_invoice_items');
        Schema::dropIfExists('billing_invoices');
        Schema::dropIfExists('usage_snapshots');
        Schema::dropIfExists('usage_aggregates');
        Schema::dropIfExists('usage_metrics');
        Schema::dropIfExists('subscription_addon_purchases');
        Schema::dropIfExists('organization_subscriptions');
        Schema::dropIfExists('subscription_addons');
        Schema::dropIfExists('subscription_plans');
    }
};
