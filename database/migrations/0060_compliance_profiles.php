<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compliance_profiles', function (Blueprint $table) {
            $table->uuid('id');
            $table->uuid('org_id');
            $table->string('jurisdiction', 2);
            $table->string('engine', 32);
            $table->string('status', 32)->default('pending_onboarding');
            $table->json('settings')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->primary(['id']);
            $table->unique(['org_id', 'jurisdiction'], 'compliance_profiles_organization_id_jurisdiction_unique');
            $table->index(['org_id', 'status'], 'compliance_profiles_organization_id_status_index');
            $table->foreign('org_id', 'compliance_profiles_organization_id_foreign')->references('id')->on('organizations')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compliance_profiles');
    }
};
