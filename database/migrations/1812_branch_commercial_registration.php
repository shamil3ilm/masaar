<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A branch's own commercial registration, where it has one: "in case of
 * multiple commercial registrations, the seller should fill the commercial
 * registration of the branch in respect of which the Tax Invoice is being
 * issued" (ZATCA XML Implementation Standard v1.2, BR-KSA-08 notes, p51-52).
 * Empty means the organization's (SellerParty).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->string('cr_number', 50)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn('cr_number');
        });
    }
};
