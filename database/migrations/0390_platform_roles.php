<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What each platform admin may do in the console.
 *
 * Being a platform admin was all or nothing: every admin could approve
 * companies, issue licences, suspend accounts, change platform settings and
 * stop filing for every tenant. A role now decides which of those. Everyone
 * who is an admin today becomes an owner, so nobody loses what they had.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('platform_role', 20)->nullable();
        });

        DB::table('users')->where('is_platform_admin', true)->update(['platform_role' => 'owner']);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('platform_role');
        });
    }
};
