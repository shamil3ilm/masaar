<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A device whose filing is paused, and why: the tax authority refused its
 * credentials, so nothing more is sent from it until they are renewed.
 * Columns on an existing entity table (class a), which keeps its own
 * created_at and updated_at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('egs_units', function (Blueprint $table) {
            $table->timestamp('filing_paused_at')->nullable()->after('egs_serial');
            $table->string('filing_paused_reason', 255)->nullable()->after('filing_paused_at');
        });
    }

    public function down(): void
    {
        Schema::table('egs_units', function (Blueprint $table) {
            $table->dropColumn(['filing_paused_at', 'filing_paused_reason']);
        });
    }
};
