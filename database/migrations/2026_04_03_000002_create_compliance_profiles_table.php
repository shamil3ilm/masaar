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
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();

            // ISO 3166-1 alpha-2 country code: SA, AE, QA, …
            $table->string('jurisdiction', 2);

            // Engine slug that ComplianceRouter will resolve: fatoora, fta, gta, …
            $table->string('engine', 32);

            // Profile lifecycle: pending_onboarding | active | suspended | revoked
            $table->string('status', 32)->default('pending_onboarding');

            // Jurisdiction-specific settings blob
            $table->json('settings')->nullable();

            $table->timestamps();

            // One profile per org per jurisdiction
            $table->unique(['organization_id', 'jurisdiction']);
            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compliance_profiles');
    }
};
