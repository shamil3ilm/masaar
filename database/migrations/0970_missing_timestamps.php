<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The timestamps three tables were made without, filled from the times
 * each row already kept:
 *
 *   hash_chain_heads       created_at: when its device's chain began, its
 *                          first entry; else when it last moved.
 *   organization_licenses  created_at and updated_at: when it was linked.
 *   license_rate_limits    created_at and updated_at: when its window opened.
 *
 * Nullable columns at the end, an instant add on MySQL 8, and the fill is
 * one statement per table on tables that hold a row per device, per link
 * and per open window.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hash_chain_heads', function (Blueprint $table) {
            $table->timestamp('created_at')->nullable();
        });

        DB::table('hash_chain_heads')->update([
            'created_at' => DB::raw('coalesce((select min(h.created_at) from hash_chain_history h where h.device_id = hash_chain_heads.device_id), updated_at)'),
        ]);

        Schema::table('organization_licenses', function (Blueprint $table) {
            $table->timestamps();
        });

        DB::table('organization_licenses')->update(['created_at' => DB::raw('linked_at'), 'updated_at' => DB::raw('linked_at')]);

        Schema::table('license_rate_limits', function (Blueprint $table) {
            $table->timestamps();
        });

        DB::table('license_rate_limits')->update(['created_at' => DB::raw('window_start'), 'updated_at' => DB::raw('window_start')]);
    }

    public function down(): void
    {
        Schema::table('hash_chain_heads', function (Blueprint $table) {
            $table->dropColumn('created_at');
        });

        foreach (['organization_licenses', 'license_rate_limits'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropTimestamps();
            });
        }
    }
};
