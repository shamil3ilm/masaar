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
            $table->string('street')->nullable()->after('country');
            $table->string('building_number')->nullable()->after('street');
            $table->string('additional_street')->nullable()->after('building_number');
            $table->string('district')->nullable()->after('additional_street');
            $table->string('city')->nullable()->after('district');
            $table->string('postal_code', 5)->nullable()->after('city');
            $table->string('cr_number', 20)->nullable()->after('postal_code'); // Commercial Registration
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
