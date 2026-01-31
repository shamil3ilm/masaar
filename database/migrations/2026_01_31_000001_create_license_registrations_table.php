<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('license_registrations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->nullable()->constrained()->nullOnDelete();

            // Registration details
            $table->string('organization_name');
            $table->string('contact_name');
            $table->string('contact_email');
            $table->string('vat_number', 15)->nullable();
            $table->text('use_case_description');

            // Agreement tracking
            $table->boolean('terms_accepted')->default(false);
            $table->timestamp('terms_accepted_at')->nullable();
            $table->string('terms_version')->default('1.0');
            $table->ipAddress('accepted_from_ip')->nullable();

            // License status
            $table->enum('status', ['pending', 'approved', 'rejected', 'suspended', 'revoked'])->default('pending');
            $table->string('license_type')->default('commercial'); // commercial, educational, non-profit
            $table->text('rejection_reason')->nullable();

            // Verification
            $table->string('verification_token')->nullable()->unique();
            $table->timestamp('verified_at')->nullable();

            // Metadata
            $table->string('country_code', 2)->default('SA');
            $table->string('industry')->nullable();
            $table->string('company_size')->nullable(); // small, medium, large, enterprise

            // Admin notes
            $table->text('admin_notes')->nullable();
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index('status');
            $table->index('contact_email');
            $table->index('vat_number');
            $table->index('created_at');
        });

        // Audit log for registration changes
        Schema::create('license_registration_audits', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('registration_id')->constrained('license_registrations')->cascadeOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('action'); // created, approved, rejected, suspended, revoked, updated
            $table->string('old_status')->nullable();
            $table->string('new_status')->nullable();
            $table->json('changes')->nullable();
            $table->text('reason')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->string('user_agent')->nullable();

            $table->timestamps();

            $table->index(['registration_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('license_registration_audits');
        Schema::dropIfExists('license_registrations');
    }
};
