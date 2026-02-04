<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Work schedules / shifts
        Schema::create('work_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('code', 20)->nullable();
            $table->time('start_time');
            $table->time('end_time');
            $table->decimal('break_duration', 4, 2)->default(0); // in hours
            $table->decimal('working_hours', 4, 2)->default(8);
            $table->json('work_days')->nullable(); // [1,2,3,4,5] for Mon-Fri
            $table->boolean('is_flexible')->default(false);
            $table->unsignedSmallInteger('grace_period_minutes')->default(0);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'code']);
        });

        // Holidays
        Schema::create('holidays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 100);
            $table->date('holiday_date');
            $table->boolean('is_optional')->default(false);
            $table->boolean('is_restricted')->default(false); // Only for certain religions/groups
            $table->string('applicable_to', 50)->nullable(); // all, specific_department, etc.
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'holiday_date']);
        });

        // Attendance records
        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('attendance_date');
            $table->foreignId('work_schedule_id')->nullable()->constrained()->nullOnDelete();

            // Check in/out
            $table->datetime('check_in')->nullable();
            $table->datetime('check_out')->nullable();
            $table->datetime('break_start')->nullable();
            $table->datetime('break_end')->nullable();

            // Calculated hours
            $table->decimal('working_hours', 5, 2)->default(0);
            $table->decimal('overtime_hours', 5, 2)->default(0);
            $table->decimal('break_hours', 5, 2)->default(0);
            $table->integer('late_minutes')->default(0);
            $table->integer('early_leaving_minutes')->default(0);

            // Status
            $table->enum('status', [
                'present',
                'absent',
                'half_day',
                'on_leave',
                'holiday',
                'weekend',
                'work_from_home',
                'on_duty', // Official duty outside office
            ])->default('present');

            // Source of entry
            $table->enum('source', ['manual', 'biometric', 'geo_fence', 'import'])->default('manual');
            $table->string('device_id', 100)->nullable();
            $table->decimal('check_in_latitude', 10, 8)->nullable();
            $table->decimal('check_in_longitude', 11, 8)->nullable();
            $table->decimal('check_out_latitude', 10, 8)->nullable();
            $table->decimal('check_out_longitude', 11, 8)->nullable();

            // Approval for regularization
            $table->boolean('is_regularized')->default(false);
            $table->string('regularization_reason', 500)->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->datetime('approved_at')->nullable();

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'attendance_date']);
            $table->index(['organization_id', 'attendance_date']);
            $table->index(['employee_id', 'status']);
        });

        // Attendance regularization requests
        Schema::create('attendance_regularizations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('attendance_id')->constrained()->cascadeOnDelete();
            $table->datetime('requested_check_in')->nullable();
            $table->datetime('requested_check_out')->nullable();
            $table->string('reason', 500);
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->datetime('approved_at')->nullable();
            $table->string('rejection_reason', 500)->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index(['employee_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_regularizations');
        Schema::dropIfExists('attendances');
        Schema::dropIfExists('holidays');
        Schema::dropIfExists('work_schedules');
    }
};
