<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One theme per scope, the platform's included.
 *
 * org_id is unique, but it is null for the platform's theme and a unique
 * index allows any number of nulls, so two first saves at once could create
 * two platform themes and serve either. scope is never null - "platform" or
 * the organization's id - and its index allows one of each.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('themes', function (Blueprint $table) {
            $table->string('scope', 36)->nullable()->after('id');
        });

        DB::table('themes')->update(['scope' => DB::raw("COALESCE(org_id, 'platform')")]);

        // Should two platform rows exist already, the newest is the one kept.
        $platform = DB::table('themes')->where('scope', 'platform')->orderByDesc('updated_at')->pluck('id');
        if ($platform->count() > 1) {
            DB::table('themes')->whereIn('id', $platform->slice(1)->all())->delete();
        }

        Schema::table('themes', function (Blueprint $table) {
            $table->unique('scope', 'themes_scope_unique');
        });
    }

    public function down(): void
    {
        Schema::table('themes', function (Blueprint $table) {
            $table->dropUnique('themes_scope_unique');
            $table->dropColumn('scope');
        });
    }
};
