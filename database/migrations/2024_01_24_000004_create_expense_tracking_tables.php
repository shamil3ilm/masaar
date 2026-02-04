<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Expense categories
        Schema::create('expense_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('expense_categories')->nullOnDelete();
            $table->string('name');
            $table->string('code', 20)->nullable();
            $table->string('icon', 50)->nullable();
            $table->string('color', 7)->nullable();
            $table->text('description')->nullable();
            $table->foreignId('default_account_id')->nullable()->constrained('chart_of_accounts')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->boolean('requires_receipt')->default(false);
            $table->decimal('budget_limit', 15, 2)->nullable(); // Monthly budget
            $table->timestamps();

            $table->unique(['organization_id', 'name', 'parent_id']);
        });

        // Expenses
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('expense_number', 30);
            $table->foreignId('category_id')->constrained('expense_categories')->cascadeOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->date('expense_date');
            $table->date('due_date')->nullable();
            $table->string('payment_method', 30)->nullable(); // cash, card, bank_transfer, petty_cash
            $table->string('reference')->nullable();
            $table->text('description');
            $table->string('currency_code', 3)->default('SAR');
            $table->decimal('exchange_rate', 10, 6)->default(1);
            $table->decimal('amount', 15, 2);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('total_amount', 15, 2);
            $table->decimal('base_amount', 15, 2); // In base currency
            $table->string('status', 20)->default('draft'); // draft, submitted, approved, rejected, paid, cancelled
            $table->boolean('is_reimbursable')->default(false);
            $table->boolean('is_recurring')->default(false);
            $table->foreignId('recurring_expense_id')->nullable(); // Parent recurring expense
            $table->boolean('is_billable')->default(false);
            $table->foreignId('project_id')->nullable(); // If billable to project
            $table->foreignId('customer_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignId('account_id')->nullable()->constrained('chart_of_accounts')->nullOnDelete();
            $table->foreignId('bank_account_id')->nullable()->constrained('bank_accounts')->nullOnDelete();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('bill_id')->nullable(); // Converted to payable
            $table->text('notes')->nullable();
            $table->json('custom_fields')->nullable();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'expense_date']);
            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'category_id']);
            $table->index(['employee_id', 'status']);
        });

        // Expense line items (for multi-line expenses)
        Schema::create('expense_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expense_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('expense_categories')->nullOnDelete();
            $table->text('description');
            $table->decimal('amount', 15, 2);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('total_amount', 15, 2);
            $table->foreignId('account_id')->nullable()->constrained('chart_of_accounts')->nullOnDelete();
            $table->unsignedTinyInteger('line_order')->default(0);
            $table->timestamps();
        });

        // Expense receipts
        Schema::create('expense_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expense_id')->constrained()->cascadeOnDelete();
            $table->string('file_name');
            $table->string('file_path');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('file_size');
            $table->text('ocr_text')->nullable(); // Extracted text from receipt
            $table->json('ocr_data')->nullable(); // Parsed OCR data (amount, date, vendor)
            $table->foreignId('uploaded_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
        });

        // Recurring expenses
        Schema::create('recurring_expenses', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('expense_categories')->cascadeOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('amount', 15, 2);
            $table->string('currency_code', 3)->default('SAR');
            $table->string('frequency', 20); // daily, weekly, monthly, quarterly, yearly
            $table->unsignedTinyInteger('frequency_interval')->default(1);
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->date('next_occurrence');
            $table->unsignedInteger('occurrences_count')->default(0);
            $table->unsignedInteger('max_occurrences')->nullable();
            $table->boolean('auto_approve')->default(false);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'next_occurrence']);
            $table->index(['organization_id', 'is_active']);
        });

        // Expense reports (grouping expenses for reimbursement)
        Schema::create('expense_reports', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('report_number', 30);
            $table->string('title');
            $table->text('description')->nullable();
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->decimal('approved_amount', 15, 2)->default(0);
            $table->decimal('reimbursed_amount', 15, 2)->default(0);
            $table->string('status', 20)->default('draft'); // draft, submitted, approved, rejected, paid
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index(['employee_id', 'status']);
        });

        // Link expenses to reports
        Schema::create('expense_report_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_id')->constrained('expense_reports')->cascadeOnDelete();
            $table->foreignId('expense_id')->constrained()->cascadeOnDelete();
            $table->decimal('approved_amount')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['report_id', 'expense_id']);
        });

        // Budget tracking per category
        Schema::create('expense_budgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('expense_categories')->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month')->nullable(); // NULL for annual budget
            $table->decimal('budget_amount', 15, 2);
            $table->decimal('spent_amount', 15, 2)->default(0);
            $table->decimal('committed_amount', 15, 2)->default(0); // Pending approvals
            $table->boolean('alert_at_80')->default(true);
            $table->boolean('alert_at_100')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'category_id', 'department_id', 'year', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_budgets');
        Schema::dropIfExists('expense_report_items');
        Schema::dropIfExists('expense_reports');
        Schema::dropIfExists('recurring_expenses');
        Schema::dropIfExists('expense_receipts');
        Schema::dropIfExists('expense_items');
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('expense_categories');
    }
};
