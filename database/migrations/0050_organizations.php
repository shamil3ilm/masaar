<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->uuid('id');
            $table->uuid('group_id')->nullable();
            $table->string('name', 255);
            $table->string('vat_number', 15)->nullable();
            $table->string('country', 2)->default('SA');
            $table->string('street', 255)->nullable();
            $table->string('building_number', 255)->nullable();
            $table->string('additional_street', 255)->nullable();
            $table->string('district', 255)->nullable();
            $table->string('city', 255)->nullable();
            $table->string('postal_code', 5)->nullable();
            $table->string('cr_number', 20)->nullable();
            $table->string('status', 255)->default('active');
            $table->json('compliance_profile')->nullable();
            $table->string('hold_ref', 255)->nullable();
            $table->timestamp('legal_hold_at')->nullable();
            $table->timestamp('hold_expires_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->primary(['id']);
            $table->index(['group_id'], 'organizations_group_id_foreign');
            $table->index(['hold_ref'], 'organizations_legal_hold_idx');
            $table->index(['status'], 'organizations_status_index');
            $table->index(['vat_number'], 'organizations_vat_number_index');
            $table->foreign('group_id', 'organizations_group_id_foreign')->references('id')->on('organization_groups')->nullOnDelete();
        });

        Schema::create('organization_user', function (Blueprint $table) {
            $table->uuid('user_id');
            $table->uuid('org_id');
            $table->string('role', 255)->default('member');
            $table->string('status', 255)->default('active');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->primary(['user_id', 'org_id']);
            $table->index(['org_id', 'status'], 'org_user_org_status_idx');
            $table->foreign('org_id', 'organization_user_organization_id_foreign')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('user_id', 'organization_user_user_id_foreign')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_user');
        Schema::dropIfExists('organizations');
    }
};
