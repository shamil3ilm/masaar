<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Salary components (Basic, HRA, DA, PF, etc.)
        Schema::create('salary_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('code', 20);
            $table->text('description')->nullable();
            $table->enum('type', ['earning', 'deduction'])->default('earning');
            $table->enum('category', [
                'basic',
                'allowance',
                'bonus',
                'reimbursement',
                'statutory_deduction', // PF, ESI, GOSI, etc.
                'voluntary_deduction', // Loan, advance, etc.
                'tax', // TDS, income tax, etc.
            ])->default('allowance');

            // Calculation
            $table->enum('calculation_type', ['fixed', 'percentage', 'formula'])->default('fixed');
            $table->decimal('default_value', 15, 4)->default(0);
            $table->string('percentage_of', 50)->nullable(); // Component code to calculate percentage of
            $table->string('formula', 500)->nullable();

            // Tax treatment
            $table->boolean('is_taxable')->default(true);
            $table->boolean('is_pro_rata')->default(true); // Based on days worked
            $table->boolean('is_statutory')->default(false);
            $table->boolean('is_flexible_benefit')->default(false); // Part of flexible benefit plan

            // Display
            $table->boolean('show_in_payslip')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'code']);
        });

        // Salary structures/templates
        Schema::create('salary_structures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('code', 20);
            $table->text('description')->nullable();
            $table->string('currency_code', 3)->default('SAR');
            $table->enum('payroll_frequency', ['monthly', 'bi_weekly', 'weekly'])->default('monthly');
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'code']);
        });

        // Salary structure components
        Schema::create('salary_structure_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('salary_structure_id')->constrained()->cascadeOnDelete();
            $table->foreignId('salary_component_id')->constrained()->cascadeOnDelete();
            $table->enum('calculation_type', ['fixed', 'percentage', 'formula'])->nullable();
            $table->decimal('value', 15, 4)->default(0);
            $table->string('percentage_of', 50)->nullable();
            $table->string('formula', 500)->nullable();
            $table->timestamps();

            $table->unique(['salary_structure_id', 'salary_component_id'], 'structure_component_unique');
        });

        // Employee salary assignments
        Schema::create('employee_salaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('salary_structure_id')->constrained()->cascadeOnDelete();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->decimal('ctc', 15, 4)->default(0); // Cost to company (annual)
            $table->decimal('gross_salary', 15, 4)->default(0); // Monthly gross
            $table->decimal('net_salary', 15, 4)->default(0); // Monthly net
            $table->string('currency_code', 3)->default('SAR');
            $table->string('reason_for_change', 500)->nullable();
            $table->boolean('is_current')->default(true);
            $table->timestamps();

            $table->index(['employee_id', 'is_current']);
            $table->index(['employee_id', 'effective_from']);
        });

        // Employee salary component overrides
        Schema::create('employee_salary_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_salary_id')->constrained()->cascadeOnDelete();
            $table->foreignId('salary_component_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 15, 4)->default(0);
            $table->timestamps();

            $table->unique(['employee_salary_id', 'salary_component_id'], 'emp_salary_component_unique');
        });

        // Payroll periods
        Schema::create('payroll_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 50);
            $table->date('start_date');
            $table->date('end_date');
            $table->date('payment_date')->nullable();
            $table->enum('status', ['open', 'processing', 'processed', 'closed'])->default('open');
            $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->datetime('processed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->datetime('closed_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'start_date', 'end_date']);
            $table->index(['organization_id', 'status']);
        });

        // Payslips
        Schema::create('payslips', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payroll_period_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_salary_id')->constrained()->cascadeOnDelete();

            // Period info
            $table->string('payslip_number', 50);
            $table->date('payment_date')->nullable();

            // Work days
            $table->decimal('total_working_days', 5, 2)->default(0);
            $table->decimal('days_worked', 5, 2)->default(0);
            $table->decimal('days_on_leave', 5, 2)->default(0);
            $table->decimal('unpaid_leave_days', 5, 2)->default(0);
            $table->decimal('overtime_hours', 6, 2)->default(0);

            // Amounts
            $table->decimal('gross_earnings', 15, 4)->default(0);
            $table->decimal('total_deductions', 15, 4)->default(0);
            $table->decimal('net_salary', 15, 4)->default(0);
            $table->string('currency_code', 3)->default('SAR');

            // Tax info (for India)
            $table->decimal('taxable_income', 15, 4)->default(0);
            $table->decimal('tax_deducted', 15, 4)->default(0);

            // Status
            $table->enum('status', ['draft', 'pending', 'approved', 'paid', 'cancelled'])->default('draft');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->datetime('approved_at')->nullable();

            // Payment
            $table->string('payment_mode', 20)->nullable();
            $table->string('payment_reference', 100)->nullable();
            $table->datetime('paid_at')->nullable();

            // Journal entry
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();

            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'payslip_number']);
            $table->unique(['payroll_period_id', 'employee_id']);
            $table->index(['organization_id', 'status']);
        });

        // Payslip line items
        Schema::create('payslip_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payslip_id')->constrained()->cascadeOnDelete();
            $table->foreignId('salary_component_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['earning', 'deduction']);
            $table->string('name', 100);
            $table->decimal('amount', 15, 4)->default(0);
            $table->decimal('ytd_amount', 15, 4)->default(0); // Year to date
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('payslip_id');
        });

        // Loans and advances
        Schema::create('employee_loans', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('loan_number', 50);
            $table->enum('loan_type', ['loan', 'advance', 'salary_advance'])->default('loan');
            $table->decimal('principal_amount', 15, 4);
            $table->decimal('interest_rate', 5, 4)->default(0); // Annual %
            $table->date('disbursement_date');
            $table->date('repayment_start_date');
            $table->unsignedSmallInteger('tenure_months');
            $table->decimal('emi_amount', 15, 4);
            $table->decimal('total_repaid', 15, 4)->default(0);
            $table->decimal('balance', 15, 4);
            $table->enum('status', ['pending', 'active', 'completed', 'cancelled'])->default('pending');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->datetime('approved_at')->nullable();
            $table->string('currency_code', 3)->default('SAR');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'loan_number']);
            $table->index(['organization_id', 'status']);
            $table->index(['employee_id', 'status']);
        });

        // Loan repayment schedule
        Schema::create('loan_repayments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_loan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payslip_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('installment_number');
            $table->date('due_date');
            $table->decimal('principal_amount', 15, 4);
            $table->decimal('interest_amount', 15, 4)->default(0);
            $table->decimal('total_amount', 15, 4);
            $table->decimal('amount_paid', 15, 4)->default(0);
            $table->date('paid_date')->nullable();
            $table->enum('status', ['pending', 'paid', 'partial', 'skipped'])->default('pending');
            $table->timestamps();

            $table->index(['employee_loan_id', 'status']);
            $table->index('due_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_repayments');
        Schema::dropIfExists('employee_loans');
        Schema::dropIfExists('payslip_items');
        Schema::dropIfExists('payslips');
        Schema::dropIfExists('payroll_periods');
        Schema::dropIfExists('employee_salary_components');
        Schema::dropIfExists('employee_salaries');
        Schema::dropIfExists('salary_structure_components');
        Schema::dropIfExists('salary_structures');
        Schema::dropIfExists('salary_components');
    }
};
