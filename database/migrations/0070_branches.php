<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->uuid('id');
            $table->uuid('org_id');
            $table->string('name', 255);
            $table->string('name_ar', 255)->nullable();
            $table->string('device_serial', 255);
            $table->string('industry', 255)->default('General');
            $table->string('street', 255);
            $table->string('building_number', 4);
            $table->string('additional_number', 4)->nullable();
            $table->string('district', 255);
            $table->string('city', 255);
            $table->string('postal_code', 5);
            $table->string('country_code', 2)->default('SA');
            $table->enum('onboarding_status', ['pending', 'csr_generated', 'compliance_passed', 'active', 'suspended', 'revoked'])->default('pending');
            $table->timestamp('onboarded_at')->nullable();
            $table->timestamp('cert_expires_at')->nullable();
            $table->unsignedBigInteger('invoice_count')->default(0);
            $table->timestamp('last_invoice_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->primary(['id']);
            $table->unique(['device_serial'], 'branches_device_serial_unique');
            $table->index(['org_id', 'is_active'], 'branches_organization_id_is_active_index');
            $table->index(['org_id', 'is_default'], 'branches_organization_id_is_default_index');
            $table->index(['org_id', 'onboarding_status'], 'branches_organization_id_onboarding_status_index');
            $table->foreign('org_id', 'branches_organization_id_foreign')->references('id')->on('organizations')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branches');
    }
};
