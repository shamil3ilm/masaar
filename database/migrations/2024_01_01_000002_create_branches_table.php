<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('code', 20); // Branch code (unique within org)

            // Address
            $table->string('address_line_1')->nullable();
            $table->string('address_line_2')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->string('country_code', 2)->nullable();

            // Contact
            $table->string('phone', 20)->nullable();
            $table->string('email')->nullable();

            // Tax Number (for branches with separate tax registration)
            $table->string('tax_number', 50)->nullable();

            // Compliance Credentials (encrypted JSON)
            // For ZATCA: CCSID, PCSID, etc.
            $table->text('compliance_credentials')->nullable();
            $table->string('compliance_status', 20)->default('pending'); // pending, active, suspended

            // Status
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->unique(['organization_id', 'code']);
            $table->index('is_active');
            $table->index('is_default');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branches');
    }
};
