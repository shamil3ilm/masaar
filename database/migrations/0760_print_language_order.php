<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which language a bilingual invoice prints first: null for the platform's
 * default (Arabic first, right to left), or the organization's own choice
 * (PrintPreferences::languageOrder). Never one language alone: the other is
 * printed beside it either way.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('print_language_order', 16)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('print_language_order');
        });
    }
};
