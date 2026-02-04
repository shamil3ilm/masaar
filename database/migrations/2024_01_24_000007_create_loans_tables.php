<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Loans (both inter-company and intra-company/employee)
        Schema::create('loans', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('loan_number', 30);
            $table->string('loan_type', 30); // employee_loan, inter_company, intra_company, bank_loan
            $table->string('loan_category', 50)->nullable(); // personal, salary_advance, housing, vehicle, education

            // Borrower
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete(); // For inter-company
            $table->string('borrower_name')->nullable();

            // Lender
            $table->string('lender_type', 30); // organization, bank, other
            $table->string('lender_name')->nullable();
            $table->foreignId('lender_contact_id')->nullable()->constrained('contacts')->nullOnDelete();

            // Loan details
            $table->decimal('principal_amount', 15, 2);
            $table->decimal('interest_rate', 5, 2)->default(0); // Annual rate
            $table->string('interest_type', 20)->default('simple'); // simple, compound, flat
            $table->decimal('total_interest', 15, 2)->default(0);
            $table->decimal('total_amount', 15, 2);
            $table->decimal('outstanding_amount', 15, 2);
            $table->string('currency_code', 3)->default('SAR');

            // Terms
            $table->date('disbursement_date');
            $table->date('first_payment_date');
            $table->date('maturity_date');
            $table->unsignedInteger('tenure_months');
            $table->string('payment_frequency', 20)->default('monthly'); // weekly, bi-weekly, monthly
            $table->decimal('emi_amount', 15, 2); // Equated Monthly Installment
            $table->unsignedInteger('total_installments');
            $table->unsignedInteger('paid_installments')->default(0);

            // Status
            $table->string('status', 20)->default('pending'); // pending, approved, active, completed, defaulted, written_off
            $table->string('approval_status', 20)->default('pending'); // pending, approved, rejected

            // Accounting
            $table->foreignId('loan_account_id')->nullable()->constrained('chart_of_accounts')->nullOnDelete();
            $table->foreignId('interest_account_id')->nullable()->constrained('chart_of_accounts')->nullOnDelete();
            $table->foreignId('bank_account_id')->nullable()->constrained('bank_accounts')->nullOnDelete();

            // For employee loans - payroll deduction
            $table->boolean('deduct_from_payroll')->default(false);
            $table->decimal('monthly_deduction', 15, 2)->nullable();

            $table->text('purpose')->nullable();
            $table->text('terms_conditions')->nullable();
            $table->json('documents')->nullable();

            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'status']);
            $table->index(['employee_id', 'status']);
            $table->index(['loan_type', 'status']);
        });

        // Loan repayment schedule
        Schema::create('loan_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('installment_number');
            $table->date('due_date');
            $table->decimal('principal_amount', 15, 2);
            $table->decimal('interest_amount', 15, 2);
            $table->decimal('total_amount', 15, 2);
            $table->decimal('outstanding_balance', 15, 2);
            $table->string('status', 20)->default('pending'); // pending, paid, partial, overdue
            $table->decimal('paid_amount', 15, 2)->default(0);
            $table->date('paid_date')->nullable();
            $table->timestamps();

            $table->unique(['loan_id', 'installment_number']);
            $table->index(['loan_id', 'due_date', 'status']);
        });

        // Loan payments
        Schema::create('loan_payments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('loan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('schedule_id')->nullable()->constrained('loan_schedules')->nullOnDelete();
            $table->date('payment_date');
            $table->decimal('principal_paid', 15, 2)->default(0);
            $table->decimal('interest_paid', 15, 2)->default(0);
            $table->decimal('penalty_paid', 15, 2)->default(0);
            $table->decimal('total_paid', 15, 2);
            $table->string('payment_method', 30); // cash, bank_transfer, payroll_deduction
            $table->string('reference')->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('payroll_id')->nullable(); // If deducted from payroll
            $table->text('notes')->nullable();
            $table->foreignId('received_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->index(['loan_id', 'payment_date']);
        });

        // Inter-company transfers
        Schema::create('inter_company_transfers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('transfer_number', 30);
            $table->string('transfer_type', 30); // fund_transfer, loan, investment

            // From
            $table->foreignId('from_branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('from_bank_account_id')->nullable()->constrained('bank_accounts')->nullOnDelete();

            // To
            $table->foreignId('to_branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('to_bank_account_id')->nullable()->constrained('bank_accounts')->nullOnDelete();
            $table->foreignId('to_organization_id')->nullable(); // For inter-company

            $table->decimal('amount', 15, 2);
            $table->string('currency_code', 3)->default('SAR');
            $table->date('transfer_date');
            $table->string('reference')->nullable();
            $table->text('purpose')->nullable();
            $table->string('status', 20)->default('pending'); // pending, approved, completed, cancelled

            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('loan_id')->nullable()->constrained()->nullOnDelete();

            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'transfer_date']);
            $table->index(['from_branch_id', 'to_branch_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inter_company_transfers');
        Schema::dropIfExists('loan_payments');
        Schema::dropIfExists('loan_schedules');
        Schema::dropIfExists('loans');
    }
};
