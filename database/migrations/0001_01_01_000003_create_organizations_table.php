<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('country', 2)->default('SA'); // ISO country code
            $table->string('status')->default('active'); // active, suspended, deleted
            $table->json('compliance_profile')->nullable(); // ZATCA settings
            $table->timestamps();
        });

        // Pivot: User <-> Organization membership
        Schema::create('organization_user', function (Blueprint $table) {
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('role')->default('member'); // admin, member
            $table->string('status')->default('active'); // active, invited, removed
            $table->timestamps();

            $table->primary(['user_id', 'organization_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_user');
        Schema::dropIfExists('organizations');
    }
};
