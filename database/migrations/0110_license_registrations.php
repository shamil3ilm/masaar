<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('license_registrations', function (Blueprint $table) {
            $table->uuid('id');
            $table->uuid('org_id')->nullable();
            $table->string('organization_name', 255);
            $table->string('contact_name', 255);
            $table->string('contact_email', 255);
            $table->string('vat_number', 15)->nullable();
            $table->text('use_case');
            $table->boolean('terms_accepted')->default(false);
            $table->timestamp('terms_accepted_at')->nullable();
            $table->string('terms_version', 255)->default('1.0');
            $table->string('accepted_from_ip', 45)->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected', 'suspended', 'revoked'])->default('pending');
            $table->string('license_type', 255)->default('commercial');
            $table->text('rejection_reason')->nullable();
            $table->string('verify_token', 255)->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->string('country_code', 2)->default('SA');
            $table->string('industry', 255)->nullable();
            $table->string('company_size', 255)->nullable();
            $table->text('admin_notes')->nullable();
            $table->uuid('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->primary(['id']);
            $table->index(['approved_by'], 'license_registrations_approved_by_foreign');
            $table->index(['contact_email'], 'license_registrations_contact_email_index');
            $table->index(['created_at'], 'license_registrations_created_at_index');
            $table->index(['org_id'], 'license_registrations_organization_id_foreign');
            $table->index(['status'], 'license_registrations_status_index');
            $table->index(['vat_number'], 'license_registrations_vat_number_index');
            $table->unique(['verify_token'], 'license_registrations_verification_token_unique');
            $table->foreign('approved_by', 'license_registrations_approved_by_foreign')->references('id')->on('users')->nullOnDelete();
            $table->foreign('org_id', 'license_registrations_organization_id_foreign')->references('id')->on('organizations')->nullOnDelete();
        });

        Schema::create('license_registration_audits', function (Blueprint $table) {
            $table->uuid('id');
            $table->uuid('registration_id');
            $table->uuid('user_id')->nullable();
            $table->string('action', 255);
            $table->string('old_status', 255)->nullable();
            $table->string('new_status', 255)->nullable();
            $table->json('changes')->nullable();
            $table->text('reason')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->primary(['id']);
            $table->index(['registration_id', 'created_at'], 'license_registration_audits_registration_id_created_at_index');
            $table->index(['user_id'], 'license_registration_audits_user_id_foreign');
            $table->foreign('registration_id', 'license_registration_audits_registration_id_foreign')->references('id')->on('license_registrations')->cascadeOnDelete();
            $table->foreign('user_id', 'license_registration_audits_user_id_foreign')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('license_registration_audits');
        Schema::dropIfExists('license_registrations');
    }
};
