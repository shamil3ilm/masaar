<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add address fields to organizations table for ZATCA compliance.
 *
 * Address is required for both seller and buyer in ZATCA Phase 2.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            if (!Schema::hasColumn('organizations', 'street')) {
                $table->string('street')->nullable()->after('country');
            }
            if (!Schema::hasColumn('organizations', 'building_number')) {
                $table->string('building_number')->nullable()->after('street');
            }
            if (!Schema::hasColumn('organizations', 'additional_street')) {
                $table->string('additional_street')->nullable()->after('building_number');
            }
            if (!Schema::hasColumn('organizations', 'district')) {
                $table->string('district')->nullable()->after('additional_street');
            }
            if (!Schema::hasColumn('organizations', 'city')) {
                $table->string('city')->nullable()->after('district');
            }
            if (!Schema::hasColumn('organizations', 'postal_code')) {
                $table->string('postal_code', 5)->nullable()->after('city');
            }
            if (!Schema::hasColumn('organizations', 'cr_number')) {
                $table->string('cr_number', 20)->nullable()->after('postal_code');
            }
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn([
                'street',
                'building_number',
                'additional_street',
                'district',
                'city',
                'postal_code',
                'cr_number',
            ]);
        });
    }
};
