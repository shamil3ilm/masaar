<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Leave types
        Schema::create('leave_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('code', 20);
            $table->text('description')->nullable();

            // Allocation rules
            $table->decimal('annual_quota', 5, 2)->default(0); // Days per year
            $table->boolean('is_paid')->default(true);
            $table->boolean('is_encashable')->default(false);
            $table->decimal('max_encashable_days', 5, 2)->default(0);
            $table->boolean('carry_forward')->default(false);
            $table->decimal('max_carry_forward_days', 5, 2)->default(0);

            // Application rules
            $table->unsignedSmallInteger('min_days_notice')->default(0);
            $table->decimal('max_consecutive_days', 5, 2)->nullable();
            $table->boolean('requires_attachment')->default(false);
            $table->unsignedSmallInteger('attachment_required_after_days')->default(0);
            $table->boolean('half_day_allowed')->default(true);
            $table->boolean('requires_approval')->default(true);

            // Applicability
            $table->enum('applicable_gender', ['all', 'male', 'female'])->default('all');
            $table->enum('applicable_marital_status', ['all', 'married', 'single'])->default('all');
            $table->unsignedSmallInteger('applicable_after_months')->default(0); // After joining

            // Accrual settings
            $table->enum('accrual_type', ['annual', 'monthly', 'quarterly'])->default('annual');
            $table->boolean('prorate_on_joining')->default(true);
            $table->boolean('prorate_on_exit')->default(true);

            $table->string('color', 7)->nullable(); // For calendar display
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'code']);
        });

        // Employee leave balances
        Schema::create('leave_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained()->cascadeOnDelete();
            $table->year('year');

            // Balance tracking
            $table->decimal('opening_balance', 5, 2)->default(0);
            $table->decimal('accrued', 5, 2)->default(0);
            $table->decimal('taken', 5, 2)->default(0);
            $table->decimal('adjustment', 5, 2)->default(0);
            $table->decimal('encashed', 5, 2)->default(0);
            $table->decimal('lapsed', 5, 2)->default(0);
            $table->decimal('closing_balance', 5, 2)->default(0);

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'leave_type_id', 'year']);
            $table->index(['organization_id', 'year']);
        });

        // Leave requests
        Schema::create('leave_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained()->cascadeOnDelete();

            // Leave period
            $table->date('from_date');
            $table->date('to_date');
            $table->decimal('total_days', 5, 2);
            $table->boolean('is_half_day')->default(false);
            $table->enum('half_day_type', ['first_half', 'second_half'])->nullable();

            // Request details
            $table->string('reason', 500)->nullable();
            $table->string('contact_during_leave', 100)->nullable();
            $table->string('address_during_leave', 500)->nullable();

            // Status
            $table->enum('status', [
                'draft',
                'pending',
                'approved',
                'rejected',
                'cancelled',
            ])->default('draft');

            // Approval workflow
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->datetime('approved_at')->nullable();
            $table->string('rejection_reason', 500)->nullable();
            $table->datetime('cancelled_at')->nullable();
            $table->string('cancellation_reason', 500)->nullable();

            // Attachment
            $table->string('attachment_path', 500)->nullable();

            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'status']);
            $table->index(['employee_id', 'status']);
            $table->index(['from_date', 'to_date']);
        });

        // Compensatory off (for working on holidays/weekends)
        Schema::create('compensatory_offs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('worked_date');
            $table->string('reason', 500);
            $table->decimal('days_earned', 3, 1)->default(1);
            $table->date('valid_until');
            $table->decimal('days_used', 3, 1)->default(0);
            $table->decimal('days_expired', 3, 1)->default(0);
            $table->enum('status', ['pending', 'approved', 'rejected', 'used', 'expired'])->default('pending');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->datetime('approved_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index(['employee_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compensatory_offs');
        Schema::dropIfExists('leave_requests');
        Schema::dropIfExists('leave_balances');
        Schema::dropIfExists('leave_types');
    }
};
