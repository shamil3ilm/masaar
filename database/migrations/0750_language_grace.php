<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A platform admin's grace for one organization: until the date, an invoice
 * missing the Arabic text its country requires is drafted with a warning
 * instead of refused (LanguageGrace). With the reason and who granted it.
 *
 * An entity, one per organization: created when first granted, updated when
 * changed or withdrawn (no date), never removed - its history of changes is
 * the admin audit log, which records each one before and after. A small
 * record of its own rather than a settings store, which the platform does
 * not have yet; LanguageGrace alone reads and writes it, so a store can take
 * it over by changing that class.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('language_graces', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id')->unique();
            $table->date('until')->nullable();
            $table->string('reason', 500)->nullable();
            $table->string('granted_by')->nullable();
            $table->timestamps();

            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('language_graces');
    }
};
