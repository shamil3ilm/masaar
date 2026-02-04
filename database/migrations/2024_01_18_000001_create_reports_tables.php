<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Saved/Scheduled reports
        Schema::create('saved_reports', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('report_type', 50); // balance_sheet, cash_flow, trial_balance, etc.
            $table->json('parameters')->nullable(); // date ranges, filters, etc.
            $table->json('columns')->nullable(); // selected columns
            $table->string('schedule_frequency')->nullable(); // daily, weekly, monthly
            $table->string('schedule_day')->nullable(); // day of week/month
            $table->time('schedule_time')->nullable();
            $table->json('recipients')->nullable(); // email addresses
            $table->string('export_format', 20)->default('pdf'); // pdf, excel, csv
            $table->boolean('is_public')->default(false);
            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('next_run_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['organization_id', 'report_type']);
            $table->index(['organization_id', 'user_id']);
        });

        // Report execution history
        Schema::create('report_executions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('saved_report_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('report_type', 50);
            $table->json('parameters')->nullable();
            $table->string('status', 20)->default('pending'); // pending, processing, completed, failed
            $table->string('file_path')->nullable();
            $table->string('file_format', 20)->nullable();
            $table->unsignedInteger('file_size')->nullable();
            $table->unsignedInteger('row_count')->nullable();
            $table->unsignedInteger('execution_time_ms')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'report_type']);
            $table->index(['organization_id', 'status']);
        });

        // Financial snapshots for period-end balances
        Schema::create('financial_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fiscal_year_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('chart_of_accounts')->cascadeOnDelete();
            $table->date('snapshot_date');
            $table->string('period_type', 20); // daily, monthly, quarterly, yearly
            $table->decimal('opening_balance', 20, 4)->default(0);
            $table->decimal('debit_total', 20, 4)->default(0);
            $table->decimal('credit_total', 20, 4)->default(0);
            $table->decimal('closing_balance', 20, 4)->default(0);
            $table->decimal('base_opening_balance', 20, 4)->default(0);
            $table->decimal('base_closing_balance', 20, 4)->default(0);
            $table->timestamps();

            $table->unique(['organization_id', 'fiscal_year_id', 'account_id', 'snapshot_date', 'period_type'], 'financial_snapshots_unique');
            $table->index(['organization_id', 'snapshot_date']);
        });

        // Budget tracking
        Schema::create('budgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fiscal_year_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('budget_type', 20)->default('expense'); // expense, revenue, capital
            $table->string('period_type', 20)->default('monthly'); // monthly, quarterly, yearly
            $table->string('status', 20)->default('draft'); // draft, approved, active, closed
            $table->decimal('total_amount', 20, 4)->default(0);
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['organization_id', 'fiscal_year_id']);
        });

        Schema::create('budget_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('budget_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('chart_of_accounts')->cascadeOnDelete();
            $table->foreignId('cost_center_id')->nullable();
            $table->tinyInteger('period_number'); // 1-12 for monthly, 1-4 for quarterly
            $table->decimal('budgeted_amount', 20, 4)->default(0);
            $table->decimal('actual_amount', 20, 4)->default(0);
            $table->decimal('variance', 20, 4)->default(0);
            $table->decimal('variance_percentage', 8, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['budget_id', 'account_id', 'period_number', 'cost_center_id'], 'budget_lines_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_lines');
        Schema::dropIfExists('budgets');
        Schema::dropIfExists('financial_snapshots');
        Schema::dropIfExists('report_executions');
        Schema::dropIfExists('saved_reports');
    }
};
