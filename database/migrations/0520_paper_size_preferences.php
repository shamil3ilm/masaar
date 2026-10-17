<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The paper invoices print on, at three levels (PrintPreferences): the
 * organization's, a branch's - the till's printer - and one member's own.
 * Null below the organization means "as the level above"; null on the
 * organization means A4.
 */
return new class extends Migration
{
    private const TABLES = ['organizations', 'branches', 'organization_user'];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->string('paper_size', 8)->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropColumn('paper_size');
            });
        }
    }
};
