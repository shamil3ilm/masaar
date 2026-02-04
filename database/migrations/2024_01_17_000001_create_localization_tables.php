<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Supported languages
        Schema::create('languages', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10)->unique(); // en, ar, hi, ur, etc.
            $table->string('name'); // English, Arabic, Hindi
            $table->string('native_name'); // English, العربية, हिन्दी
            $table->string('direction', 3)->default('ltr'); // ltr, rtl
            $table->string('locale'); // en_US, ar_SA, hi_IN
            $table->string('flag_icon')->nullable(); // emoji or icon code
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        // Custom translations per organization
        Schema::create('translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('language_code', 10);
            $table->string('group'); // validation, messages, labels, invoice, etc.
            $table->string('key'); // invoice.title, button.save, etc.
            $table->text('value');
            $table->timestamps();

            $table->unique(['organization_id', 'language_code', 'group', 'key'], 'trans_unique');
            $table->index(['language_code', 'group']);
        });

        // Organization branding and customization
        Schema::create('organization_branding', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            // Logos
            $table->string('logo_url')->nullable();
            $table->string('logo_dark_url')->nullable(); // For dark mode
            $table->string('favicon_url')->nullable();
            $table->string('login_background_url')->nullable();

            // Colors
            $table->string('primary_color', 20)->default('#3498db');
            $table->string('secondary_color', 20)->default('#2ecc71');
            $table->string('accent_color', 20)->default('#9b59b6');
            $table->string('danger_color', 20)->default('#e74c3c');
            $table->string('warning_color', 20)->default('#f39c12');
            $table->string('success_color', 20)->default('#27ae60');
            $table->string('info_color', 20)->default('#3498db');
            $table->string('text_color', 20)->default('#333333');
            $table->string('background_color', 20)->default('#f8f9fa');
            $table->string('sidebar_color', 20)->default('#2c3e50');
            $table->string('header_color', 20)->default('#ffffff');

            // Typography
            $table->string('font_family')->default('Inter');
            $table->string('font_family_arabic')->default('Cairo'); // For RTL
            $table->integer('base_font_size')->default(14);

            // Theme
            $table->string('theme')->default('light'); // light, dark, auto
            $table->boolean('enable_dark_mode')->default(true);

            // Custom CSS
            $table->text('custom_css')->nullable();

            // Email branding
            $table->string('email_header_color', 20)->nullable();
            $table->string('email_footer_text')->nullable();

            // Document branding
            $table->string('document_watermark')->nullable();
            $table->string('document_footer_text')->nullable();

            $table->timestamps();

            $table->unique('organization_id');
        });

        // Dashboard layouts per user
        Schema::create('dashboard_layouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name')->default('Default');
            $table->string('type')->default('main'); // main, sales, inventory, finance
            $table->json('widgets'); // Widget configuration
            $table->json('layout'); // Grid layout positions
            $table->boolean('is_default')->default(false);
            $table->boolean('is_shared')->default(false); // Shared with org
            $table->timestamps();

            $table->index(['organization_id', 'user_id', 'type']);
        });

        // Dashboard widgets catalog
        Schema::create('dashboard_widgets', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique(); // total_sales, revenue_chart, etc.
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('category'); // kpi, chart, table, list, custom
            $table->string('type'); // number, currency, percentage, line_chart, bar_chart, pie_chart, table, list
            $table->json('default_config')->nullable();
            $table->json('available_sizes')->nullable(); // ["1x1", "2x1", "2x2", "4x2"]
            $table->string('data_source')->nullable(); // Service method or endpoint
            $table->string('permission')->nullable(); // Required permission
            $table->string('module')->nullable(); // sales, inventory, accounting
            $table->boolean('is_premium')->default(false);
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        // KPI definitions
        Schema::create('kpi_definitions', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('category'); // sales, inventory, finance, hr
            $table->string('calculation_type'); // sum, avg, count, formula, custom
            $table->text('formula')->nullable(); // SQL or calculation formula
            $table->string('data_type'); // number, currency, percentage, duration
            $table->string('trend_direction')->default('higher_better'); // higher_better, lower_better, neutral
            $table->json('thresholds')->nullable(); // {"good": 80, "warning": 50, "danger": 30}
            $table->string('comparison_period')->nullable(); // day, week, month, quarter, year
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // KPI targets per organization
        Schema::create('kpi_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kpi_code');
            $table->decimal('target_value', 20, 4);
            $table->date('period_start');
            $table->date('period_end');
            $table->string('period_type'); // daily, weekly, monthly, quarterly, yearly
            $table->json('breakdown')->nullable(); // Monthly targets within period
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'kpi_code', 'period_start']);
        });

        // Premium/Subscription features
        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('monthly_price', 10, 2)->default(0);
            $table->decimal('yearly_price', 10, 2)->default(0);
            $table->string('currency_code', 3)->default('USD');
            $table->integer('max_users')->default(1);
            $table->integer('max_branches')->default(1);
            $table->integer('max_products')->nullable();
            $table->integer('max_invoices_per_month')->nullable();
            $table->integer('storage_gb')->default(5);
            $table->json('features'); // List of feature codes
            $table->json('limits')->nullable(); // Other limits
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        // Organization subscriptions
        Schema::create('organization_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained('subscription_plans');
            $table->string('status'); // active, cancelled, expired, trial
            $table->string('billing_cycle'); // monthly, yearly
            $table->date('started_at');
            $table->date('expires_at')->nullable();
            $table->date('cancelled_at')->nullable();
            $table->date('trial_ends_at')->nullable();
            $table->json('custom_limits')->nullable(); // Override plan limits
            $table->json('addons')->nullable(); // Additional features
            $table->string('payment_method')->nullable();
            $table->string('external_subscription_id')->nullable(); // Stripe/PayPal ID
            $table->timestamps();

            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_subscriptions');
        Schema::dropIfExists('subscription_plans');
        Schema::dropIfExists('kpi_targets');
        Schema::dropIfExists('kpi_definitions');
        Schema::dropIfExists('dashboard_widgets');
        Schema::dropIfExists('dashboard_layouts');
        Schema::dropIfExists('organization_branding');
        Schema::dropIfExists('translations');
        Schema::dropIfExists('languages');
    }
};
