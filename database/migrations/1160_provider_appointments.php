<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which accredited service provider an organization appointed, and when.
 *
 * A person in scope appoints one provider for sending and receiving (UAE
 * Electronic Invoicing Guidelines v1.1, p14), by a last date its revenue
 * band sets (p19), contracting with it and onboarding through EmaraTax
 * (p38-39). The appointment is a compliance record (class c): its status
 * moves - pending, appointed, ended - so it keeps updated_at, and it is
 * never deleted; a change of provider ends one row and starts another.
 *
 * provider is a purpose key (peppol.providers), never a vendor's name;
 * reference is what the provider or EmaraTax gave for the appointment;
 * deadline_on is the organization's own last date, where it differs from
 * the platform's (peppol.countries.AE.appointment.deadline).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_appointments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id');
            $table->string('provider', 40);
            $table->string('status', 20);
            $table->timestamp('appointed_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->string('reference')->nullable();
            $table->date('deadline_on')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['org_id', 'status']);
            $table->foreign('org_id')->references('id')->on('organizations')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_appointments');
    }
};
