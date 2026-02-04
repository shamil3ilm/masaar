<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Leave policies (organization-level configurations)
        Schema::create('leave_policies', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('policy_year_type', 20)->default('calendar'); // calendar, fiscal, anniversary
            $table->date('year_start_date')->nullable(); // For fiscal/custom year
            $table->boolean('allow_negative_balance')->default(false);
            $table->boolean('require_approval')->default(true);
            $table->unsignedTinyInteger('min_notice_days')->default(0); // Days before leave start
            $table->boolean('allow_half_day')->default(true);
            $table->boolean('allow_hourly')->default(false);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['organization_id', 'is_active']);
        });

        // Leave types (annual, sick, casual, maternity, etc.)
        Schema::create('leave_types', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('leave_policy_id')->constrained('leave_policies')->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 20);
            $table->text('description')->nullable();
            $table->string('color', 7)->default('#3B82F6'); // For UI display
            $table->string('icon')->nullable();

            // Leave behavior
            $table->boolean('is_paid')->default(true);
            $table->boolean('is_encashable')->default(false); // Can be converted to cash
            $table->boolean('is_carryforward_allowed')->default(true);
            $table->unsignedSmallInteger('max_carryforward_days')->nullable();
            $table->boolean('requires_attachment')->default(false); // Medical certificate, etc.
            $table->boolean('requires_reason')->default(true);

            // Restrictions
            $table->string('gender_restriction', 10)->nullable(); // male, female, null (both)
            $table->string('employment_type_restriction', 50)->nullable(); // full_time, part_time, contract
            $table->unsignedSmallInteger('min_service_months')->default(0); // Minimum months to be eligible
            $table->unsignedSmallInteger('max_consecutive_days')->nullable();
            $table->unsignedSmallInteger('min_days_per_request')->nullable();
            $table->unsignedSmallInteger('max_days_per_request')->nullable();
            $table->json('allowed_days_of_week')->nullable(); // [1,2,3,4,5] for weekdays only
            $table->json('blackout_dates')->nullable(); // Dates when this leave cannot be taken

            // Accrual settings
            $table->string('accrual_type', 20)->default('yearly'); // yearly, monthly, weekly, none
            $table->unsignedTinyInteger('accrual_day')->nullable(); // Day of month/week for accrual

            $table->boolean('count_holidays')->default(false); // Include holidays in leave count
            $table->boolean('count_weekends')->default(false); // Include weekends in leave count

            $table->unsignedSmallInteger('display_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'code']);
            $table->index(['organization_id', 'is_active']);
        });

        // Leave tiers (entitlements based on tenure/grade)
        Schema::create('leave_tiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leave_type_id')->constrained('leave_types')->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();

            // Eligibility criteria
            $table->unsignedSmallInteger('min_service_months')->default(0); // 0 = from joining
            $table->unsignedSmallInteger('max_service_months')->nullable(); // NULL = no upper limit
            $table->string('employee_grade')->nullable(); // Specific grade requirement
            $table->string('department_id')->nullable(); // Specific department requirement

            // Entitlement
            $table->decimal('entitled_days', 5, 2); // 21, 28, 30, etc. (decimal for partial days)
            $table->string('entitlement_period', 20)->default('yearly'); // yearly, monthly

            // Accrual rate (if different from base)
            $table->decimal('monthly_accrual_rate', 5, 2)->nullable(); // Override default accrual

            // Carryforward limits for this tier
            $table->unsignedSmallInteger('max_carryforward_days')->nullable();
            $table->unsignedSmallInteger('carryforward_expiry_months')->nullable(); // How long carryforward is valid

            // Encashment limits
            $table->unsignedSmallInteger('max_encashable_days')->nullable();
            $table->decimal('encashment_rate', 5, 2)->nullable(); // Percentage of daily salary

            $table->unsignedSmallInteger('priority')->default(0); // Higher = checked first
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['leave_type_id', 'min_service_months']);
        });

        // Employee leave balances
        Schema::create('leave_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained('leave_types')->cascadeOnDelete();
            $table->foreignId('leave_tier_id')->nullable()->constrained('leave_tiers')->nullOnDelete();
            $table->unsignedSmallInteger('year');

            // Balances
            $table->decimal('opening_balance', 6, 2)->default(0);
            $table->decimal('entitled_days', 6, 2)->default(0); // From tier
            $table->decimal('accrued_days', 6, 2)->default(0); // Accumulated through accrual
            $table->decimal('adjustment_days', 6, 2)->default(0); // Manual adjustments
            $table->decimal('used_days', 6, 2)->default(0);
            $table->decimal('pending_days', 6, 2)->default(0); // Approved but not taken
            $table->decimal('carried_forward', 6, 2)->default(0); // From previous year
            $table->decimal('encashed_days', 6, 2)->default(0);
            $table->decimal('lapsed_days', 6, 2)->default(0); // Expired carryforward
            $table->decimal('available_balance', 6, 2)->default(0); // Computed balance

            $table->timestamp('last_accrual_date')->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'leave_type_id', 'year']);
            $table->index(['organization_id', 'year']);
        });

        // Leave requests
        Schema::create('leave_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained('leave_types')->cascadeOnDelete();
            $table->foreignId('leave_balance_id')->nullable()->constrained('leave_balances')->nullOnDelete();
            $table->string('request_number', 30);

            // Leave dates
            $table->date('start_date');
            $table->date('end_date');
            $table->decimal('total_days', 5, 2); // Calculated based on policy
            $table->string('day_type', 20)->default('full'); // full, first_half, second_half

            // Details
            $table->text('reason');
            $table->string('contact_during_leave')->nullable();
            $table->foreignId('delegated_to')->nullable()->constrained('employees')->nullOnDelete();

            // Status
            $table->string('status', 20)->default('pending'); // pending, approved, rejected, cancelled, taken
            $table->boolean('is_emergency')->default(false);

            // Attachments reference
            $table->boolean('has_attachments')->default(false);

            // Approval
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('approval_notes')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();

            // Cancellation
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();

            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'status']);
            $table->index(['employee_id', 'status']);
            $table->index(['start_date', 'end_date']);
        });

        // Leave request attachments
        Schema::create('leave_request_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leave_request_id')->constrained('leave_requests')->cascadeOnDelete();
            $table->string('file_name');
            $table->string('file_path');
            $table->string('file_type', 50);
            $table->unsignedInteger('file_size');
            $table->foreignId('uploaded_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->index(['leave_request_id']);
        });

        // Leave accrual log
        Schema::create('leave_accruals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leave_balance_id')->constrained('leave_balances')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->date('accrual_date');
            $table->string('accrual_type', 30); // monthly, yearly, adjustment, carryforward, opening
            $table->decimal('days', 6, 2);
            $table->text('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['leave_balance_id', 'accrual_date']);
        });

        // Leave adjustments (manual modifications)
        Schema::create('leave_adjustments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained('leave_types')->cascadeOnDelete();
            $table->foreignId('leave_balance_id')->constrained('leave_balances')->cascadeOnDelete();
            $table->string('adjustment_type', 30); // add, deduct, set, carryforward, encashment
            $table->decimal('days', 6, 2);
            $table->decimal('balance_before', 6, 2);
            $table->decimal('balance_after', 6, 2);
            $table->text('reason');
            $table->date('effective_date');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'employee_id']);
        });

        // Leave encashment requests
        Schema::create('leave_encashments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained('leave_types')->cascadeOnDelete();
            $table->foreignId('leave_balance_id')->constrained('leave_balances')->cascadeOnDelete();
            $table->decimal('requested_days', 5, 2);
            $table->decimal('approved_days', 5, 2)->nullable();
            $table->decimal('daily_rate', 15, 2); // Employee's daily salary
            $table->decimal('encashment_rate', 5, 2); // Percentage (100 = full)
            $table->decimal('amount', 15, 2)->nullable();
            $table->string('status', 20)->default('pending'); // pending, approved, rejected, paid
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('payroll_id')->nullable(); // Link to payroll when paid
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
        });

        // Public holidays (affects leave calculations)
        Schema::create('public_holidays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->date('holiday_date');
            $table->string('country_code', 3)->nullable();
            $table->string('state_code', 10)->nullable();
            $table->boolean('is_recurring')->default(false);
            $table->boolean('is_optional')->default(false);
            $table->unsignedSmallInteger('year');
            $table->timestamps();

            $table->unique(['organization_id', 'holiday_date', 'branch_id']);
            $table->index(['organization_id', 'year']);
        });

        // Leave calendar view (denormalized for performance)
        Schema::create('leave_calendar', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('leave_request_id')->constrained('leave_requests')->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained('leave_types')->cascadeOnDelete();
            $table->date('leave_date');
            $table->string('day_type', 20); // full, first_half, second_half
            $table->string('status', 20);
            $table->timestamps();

            $table->unique(['employee_id', 'leave_date', 'day_type']);
            $table->index(['organization_id', 'leave_date']);
            $table->index(['leave_request_id']);
        });

        // Leave tier access control (who can approve which tier)
        Schema::create('leave_tier_approvers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leave_tier_id')->constrained('leave_tiers')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->nullable()->constrained('roles')->cascadeOnDelete();
            $table->string('designation')->nullable(); // Alternative to specific user
            $table->unsignedTinyInteger('approval_level')->default(1); // For multi-level approval
            $table->boolean('can_approve')->default(true);
            $table->boolean('can_reject')->default(true);
            $table->boolean('is_final_approver')->default(false);
            $table->timestamps();

            $table->index(['leave_tier_id', 'approval_level']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_tier_approvers');
        Schema::dropIfExists('leave_calendar');
        Schema::dropIfExists('public_holidays');
        Schema::dropIfExists('leave_encashments');
        Schema::dropIfExists('leave_adjustments');
        Schema::dropIfExists('leave_accruals');
        Schema::dropIfExists('leave_request_attachments');
        Schema::dropIfExists('leave_requests');
        Schema::dropIfExists('leave_balances');
        Schema::dropIfExists('leave_tiers');
        Schema::dropIfExists('leave_types');
        Schema::dropIfExists('leave_policies');
    }
};
