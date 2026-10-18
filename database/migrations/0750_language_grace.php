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
 * Three columns rather than a settings store, which the platform does not
 * have yet; LanguageGrace alone reads and writes them, so a store can take
 * them over by changing that class.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->date('language_grace_until')->nullable();
            $table->string('language_grace_reason', 500)->nullable();
            $table->string('language_grace_by')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn(['language_grace_until', 'language_grace_reason', 'language_grace_by']);
        });
    }
};
