<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // Employee identification
            $table->string('employee_number', 50)->nullable();
            $table->string('first_name', 100);
            $table->string('middle_name', 100)->nullable();
            $table->string('last_name', 100);
            $table->string('display_name', 200)->nullable();

            // Personal info
            $table->date('date_of_birth')->nullable();
            $table->enum('gender', ['male', 'female', 'other'])->nullable();
            $table->enum('marital_status', ['single', 'married', 'divorced', 'widowed'])->nullable();
            $table->string('nationality', 50)->nullable();
            $table->string('blood_group', 5)->nullable();

            // Contact
            $table->string('email', 200)->nullable();
            $table->string('personal_email', 200)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('mobile', 30)->nullable();
            $table->string('emergency_contact_name', 100)->nullable();
            $table->string('emergency_contact_phone', 30)->nullable();
            $table->string('emergency_contact_relation', 50)->nullable();

            // Address
            $table->string('address_line_1', 200)->nullable();
            $table->string('address_line_2', 200)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('state', 100)->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->string('country_code', 2)->nullable();

            // Employment
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('designation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('reporting_manager_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->date('joining_date')->nullable();
            $table->date('confirmation_date')->nullable();
            $table->date('termination_date')->nullable();
            $table->string('termination_reason', 500)->nullable();
            $table->enum('employment_type', ['full_time', 'part_time', 'contract', 'intern', 'probation'])->default('full_time');
            $table->enum('employment_status', ['active', 'on_notice', 'terminated', 'resigned', 'absconded'])->default('active');

            // Work schedule
            $table->string('work_schedule', 50)->nullable(); // Reference to work schedule
            $table->time('shift_start')->nullable();
            $table->time('shift_end')->nullable();
            $table->json('work_days')->nullable(); // ['monday', 'tuesday', ...]

            // Documents
            $table->string('national_id', 50)->nullable(); // Aadhaar, Emirates ID, etc.
            $table->string('passport_number', 50)->nullable();
            $table->date('passport_expiry')->nullable();
            $table->string('visa_number', 50)->nullable();
            $table->date('visa_expiry')->nullable();
            $table->string('work_permit_number', 50)->nullable();
            $table->date('work_permit_expiry')->nullable();

            // Tax info (varies by country)
            $table->string('tax_number', 50)->nullable(); // PAN (India), TIN, etc.
            $table->string('social_security_number', 50)->nullable(); // PF number, GOSI, etc.
            $table->json('tax_declarations')->nullable(); // For India HRA, 80C, etc.

            // Salary info
            $table->string('currency_code', 3)->default('SAR');
            $table->string('payment_mode', 20)->default('bank_transfer');
            $table->string('bank_name', 100)->nullable();
            $table->string('bank_account_number', 50)->nullable();
            $table->string('bank_ifsc_code', 20)->nullable(); // IFSC for India
            $table->string('bank_iban', 50)->nullable(); // IBAN for GCC

            // Other
            $table->text('notes')->nullable();
            $table->string('profile_photo_path', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'employee_number']);
            $table->index(['organization_id', 'department_id']);
            $table->index(['organization_id', 'employment_status']);
            $table->index(['organization_id', 'is_active']);
        });

        // Employee documents
        Schema::create('employee_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('document_type', 50); // passport, id_card, contract, certificate, etc.
            $table->string('document_name', 200);
            $table->string('document_number', 100)->nullable();
            $table->date('issue_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->string('file_path', 500)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'document_type']);
            $table->index('expiry_date');
        });

        // Employee qualifications/education
        Schema::create('employee_qualifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('qualification_type', 50); // degree, diploma, certification, etc.
            $table->string('qualification_name', 200);
            $table->string('institution', 200)->nullable();
            $table->string('specialization', 200)->nullable();
            $table->year('year_of_passing')->nullable();
            $table->string('grade', 50)->nullable();
            $table->string('file_path', 500)->nullable();
            $table->timestamps();
        });

        // Employee work experience
        Schema::create('employee_experiences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('company_name', 200);
            $table->string('designation', 100)->nullable();
            $table->date('from_date');
            $table->date('to_date')->nullable();
            $table->text('responsibilities')->nullable();
            $table->string('reason_for_leaving', 500)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_experiences');
        Schema::dropIfExists('employee_qualifications');
        Schema::dropIfExists('employee_documents');
        Schema::dropIfExists('employees');
    }
};
