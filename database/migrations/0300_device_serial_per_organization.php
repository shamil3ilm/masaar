<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A device serial is unique within its organization, not across the platform.
 *
 * The generated serial is predictable - the VAT number printed on every
 * invoice, a branch count and a fixed suffix - so with a platform-wide index
 * one tenant could register another's next serial and stop it adding a
 * branch. Every organization without a VAT number also generated the same
 * first serial. ZATCA identifies a unit by its taxpayer and serial together,
 * which is what the index now says.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropUnique('branches_device_serial_unique');
            $table->unique(['org_id', 'device_serial'], 'branches_org_device_serial_unique');
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropUnique('branches_org_device_serial_unique');
            $table->unique(['device_serial'], 'branches_device_serial_unique');
        });
    }
};
