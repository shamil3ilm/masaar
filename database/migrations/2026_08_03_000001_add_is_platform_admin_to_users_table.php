<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform administrator flag.
 *
 * Platform admin is a Masaar-internal, cross-tenant privilege. It is
 * deliberately separate from the `organization_user.role = 'admin'` pivot
 * value, which only grants administration of a single tenant. Conflating the
 * two would let any customer's org-admin read the cross-tenant admin console.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_platform_admin')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_platform_admin');
        });
    }
};
