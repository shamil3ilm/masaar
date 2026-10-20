<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a feature request asks for, beside its ticket: the category (an
 * entry of the admin-edited list, requests.feature_categories), the system
 * it names when it asks for an integration, and how much it matters.
 *
 * An entity under its ticket (class a): its details are corrected as it is
 * triaged, by whoever acts on the ticket.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feature_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('ticket_id')->unique();
            $table->string('category', 40);
            // The other system an integration is with: an ERP, a store, a bank.
            $table->string('system_name', 120)->nullable();
            // nice_to_have, important or blocking.
            $table->string('importance', 20);
            $table->timestamps();

            $table->foreign('ticket_id')->references('id')->on('support_tickets')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feature_requests');
    }
};
