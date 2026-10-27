<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A request no person made: a credit or debit note an integration sent
 * through the API or the pipeline while the organization holds notes for
 * approval (NoteIssuing). requested_by is null for it; the request's
 * payload names the channel and the API key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('approval_requests', function (Blueprint $table) {
            $table->uuid('requested_by')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('approval_requests', function (Blueprint $table) {
            $table->uuid('requested_by')->nullable(false)->change();
        });
    }
};
