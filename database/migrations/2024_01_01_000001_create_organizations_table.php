<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('legal_name')->nullable();
            $table->string('slug')->unique();

            // Location & Tax Configuration
            $table->string('country_code', 2); // SA, AE, QA, OM, BH, KW, IN
            $table->string('tax_scheme', 10)->default('VAT'); // VAT, GST, NONE
            $table->string('tax_number', 50)->nullable(); // TRN for GCC, GSTIN for India
            $table->string('base_currency', 3)->default('SAR');

            // Fiscal Year
            $table->unsignedTinyInteger('fiscal_year_start_month')->default(1); // 1-12
            $table->unsignedTinyInteger('fiscal_year_start_day')->default(1); // 1-31

            // Contact Information
            $table->string('email')->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('website')->nullable();

            // Address
            $table->string('address_line_1')->nullable();
            $table->string('address_line_2')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('postal_code', 20)->nullable();

            // Settings (JSON for flexibility)
            $table->json('settings')->nullable();

            // Logo
            $table->string('logo_url')->nullable();

            // Status
            $table->boolean('is_active')->default(true);
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('suspended_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index('country_code');
            $table->index('tax_scheme');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
