<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Report definitions
        Schema::create('report_definitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete(); // NULL for system reports
            $table->string('code', 50)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('module', 30); // sales, purchase, inventory, accounting, hr, etc.
            $table->string('category', 30); // financial, operational, analytical, compliance
            $table->string('report_type', 30); // list, summary, chart, pivot, combined

            // Configuration
            $table->json('columns')->nullable(); // Available columns
            $table->json('filters')->nullable(); // Available filters
            $table->json('groupings')->nullable(); // Available groupings
            $table->json('aggregations')->nullable(); // Sum, avg, count, etc.
            $table->json('default_config')->nullable(); // Default settings

            // Output
            $table->json('available_formats'); // ['pdf', 'xlsx', 'csv', 'html']
            $table->string('default_format', 10)->default('pdf');

            // Access
            $table->string('required_permission')->nullable();
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['module', 'is_active']);
        });

        // Saved report configurations (user customizations)
        Schema::create('saved_reports', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('report_definition_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();

            // Custom configuration
            $table->json('selected_columns')->nullable();
            $table->json('filters')->nullable();
            $table->json('groupings')->nullable();
            $table->json('sorting')->nullable();
            $table->string('default_format', 10)->nullable();
            $table->json('chart_config')->nullable();

            // Scheduling
            $table->boolean('is_scheduled')->default(false);
            $table->string('schedule_frequency')->nullable(); // daily, weekly, monthly, quarterly, yearly
            $table->unsignedTinyInteger('schedule_day')->nullable(); // Day of week (1-7) or month (1-31)
            $table->time('schedule_time')->nullable();
            $table->json('schedule_recipients')->nullable(); // Email addresses
            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('next_run_at')->nullable();

            $table->boolean('is_favorite')->default(false);
            $table->boolean('is_shared')->default(false); // Shared with organization
            $table->timestamps();

            $table->index(['organization_id', 'user_id']);
            $table->index(['is_scheduled', 'next_run_at']);
        });

        // Report execution history
        Schema::create('report_executions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('saved_report_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('report_definition_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // Execution parameters
            $table->json('parameters')->nullable(); // Filters, date range, etc.
            $table->string('format', 10);
            $table->string('trigger', 20); // manual, scheduled, api

            // Status
            $table->string('status', 20)->default('pending'); // pending, running, completed, failed
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('execution_time_ms')->nullable();
            $table->unsignedInteger('row_count')->nullable();

            // Output
            $table->string('file_path')->nullable();
            $table->string('file_name')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->timestamp('expires_at')->nullable();

            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });

        // Daily summaries (pre-calculated)
        Schema::create('daily_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->date('summary_date');
            $table->string('metric_type', 50); // sales_total, invoice_count, payment_received, etc.
            $table->string('currency_code', 3)->default('SAR');

            // Values
            $table->decimal('value', 20, 4)->default(0);
            $table->decimal('count', 15, 0)->default(0);
            $table->decimal('previous_value', 20, 4)->nullable(); // Previous period comparison
            $table->decimal('change_percent', 10, 2)->nullable();

            // Breakdown
            $table->json('breakdown')->nullable(); // By category, product, customer, etc.

            $table->timestamp('calculated_at');
            $table->timestamps();

            $table->unique(['organization_id', 'branch_id', 'summary_date', 'metric_type', 'currency_code'], 'daily_summary_unique');
            $table->index(['organization_id', 'metric_type', 'summary_date']);
        });

        // Monthly summaries
        Schema::create('monthly_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->string('metric_type', 50);
            $table->string('currency_code', 3)->default('SAR');

            $table->decimal('value', 20, 4)->default(0);
            $table->decimal('count', 15, 0)->default(0);
            $table->decimal('daily_average', 20, 4)->nullable();
            $table->decimal('previous_value', 20, 4)->nullable();
            $table->decimal('yoy_change_percent', 10, 2)->nullable(); // Year over year
            $table->decimal('mom_change_percent', 10, 2)->nullable(); // Month over month

            $table->json('daily_breakdown')->nullable();
            $table->json('category_breakdown')->nullable();

            $table->timestamp('calculated_at');
            $table->timestamps();

            $table->unique(['organization_id', 'branch_id', 'year', 'month', 'metric_type', 'currency_code'], 'monthly_summary_unique');
            $table->index(['organization_id', 'metric_type', 'year', 'month']);
        });

        // Yearly summaries
        Schema::create('yearly_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('year');
            $table->string('metric_type', 50);
            $table->string('currency_code', 3)->default('SAR');

            $table->decimal('value', 20, 4)->default(0);
            $table->decimal('count', 15, 0)->default(0);
            $table->decimal('monthly_average', 20, 4)->nullable();
            $table->decimal('previous_value', 20, 4)->nullable();
            $table->decimal('yoy_change_percent', 10, 2)->nullable();

            $table->json('monthly_breakdown')->nullable();
            $table->json('quarterly_breakdown')->nullable();

            $table->timestamp('calculated_at');
            $table->timestamps();

            $table->unique(['organization_id', 'branch_id', 'year', 'metric_type', 'currency_code'], 'yearly_summary_unique');
            $table->index(['organization_id', 'metric_type', 'year']);
        });

        // Dashboard snapshots (for historical dashboard data)
        Schema::create('dashboard_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->date('snapshot_date');
            $table->string('snapshot_type', 20); // daily, weekly, monthly
            $table->json('data'); // Full dashboard data snapshot
            $table->timestamp('created_at');

            $table->unique(['organization_id', 'snapshot_date', 'snapshot_type']);
            $table->index(['organization_id', 'snapshot_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_snapshots');
        Schema::dropIfExists('yearly_summaries');
        Schema::dropIfExists('monthly_summaries');
        Schema::dropIfExists('daily_summaries');
        Schema::dropIfExists('report_executions');
        Schema::dropIfExists('saved_reports');
        Schema::dropIfExists('report_definitions');
    }
};
