<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every attempt to report or clear a document with the authority that did
 * not get an answer, and the one that finally did: when, what happened and
 * why. Append-only - the evidence of trying that ZATCA's Detailed Guidelines
 * for E-Invoicing ask a taxpayer to keep while it cannot connect (p46, p49).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reporting_attempts', function (Blueprint $table) {
            $table->id();
            $table->uuid('org_id');
            $table->uuid('invoice_id');
            $table->timestamp('attempted_at');
            // 'unreachable', 'failed' (no answer, will retry), 'gave_up'
            // (a refusal that retrying cannot change), 'filed'.
            $table->string('outcome', 16);
            $table->string('detail', 1000)->nullable();

            $table->index(['org_id', 'invoice_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reporting_attempts');
    }
};
