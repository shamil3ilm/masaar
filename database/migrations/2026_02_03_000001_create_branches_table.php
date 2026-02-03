<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create branches table for multi-EGS support.
 *
 * ZATCA requires each physical location (EGS - Electronic Generation Solution)
 * to have its own device serial and can have its own certificate.
 * This table enables organizations to register multiple branches,
 * each with independent ZATCA onboarding.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();

            // Branch identification
            $table->string('name');
            $table->string('name_ar')->nullable();
            $table->string('device_serial')->unique(); // EGS serial (CSR commonName)
            $table->string('industry')->default('General'); // Business category

            // Address (can differ from organization HQ)
            $table->string('street');
            $table->string('building_number', 4);
            $table->string('additional_number', 4)->nullable();
            $table->string('district');
            $table->string('city');
            $table->string('postal_code', 5);
            $table->string('country_code', 2)->default('SA');

            // Onboarding status
            $table->enum('onboarding_status', [
                'pending',        // Not started
                'csr_generated',  // CSR submitted, CCSID obtained
                'compliance_passed', // Compliance checks passed
                'active',         // PCSID obtained, ready for invoices
                'suspended',      // Temporarily disabled
                'revoked',        // Certificate revoked
            ])->default('pending');

            $table->timestamp('onboarded_at')->nullable();
            $table->timestamp('certificate_expires_at')->nullable();

            // Invoice counters (for branch-level tracking, optional)
            $table->unsignedBigInteger('invoice_count')->default(0);
            $table->timestamp('last_invoice_at')->nullable();

            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false); // Default branch for organization

            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index(['organization_id', 'is_active']);
            $table->index(['organization_id', 'onboarding_status']);
            $table->index(['organization_id', 'is_default']);
        });

        // Add branch_id to invoices table
        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignUuid('branch_id')->nullable()->after('organization_id')
                ->constrained('branches')->nullOnDelete();
            $table->index('branch_id');
        });

        // Ensure only one default branch per organization
        // Note: This is enforced at application level due to SQLite limitations
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('branch_id');
        });

        Schema::dropIfExists('branches');
    }
};
