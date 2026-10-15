<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A one-time link that permits a platform admin to enrol a second factor.
 *
 * Enrolment asked only for the password, so whoever held an unenrolled
 * admin's password - before their first sign-in, or after a reset - could
 * bind their own authenticator and keep the account. The link is issued by
 * another admin, or by the console command for the first one, and stored as a
 * hash: the database never holds anything that opens the enrolment page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('two_factor_enrolment_hash', 64)->nullable()->unique()->after('two_factor_confirmed_at');
            $table->timestamp('two_factor_enrolment_expires_at')->nullable()->after('two_factor_enrolment_hash');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['two_factor_enrolment_hash']);
            $table->dropColumn(['two_factor_enrolment_hash', 'two_factor_enrolment_expires_at']);
        });
    }
};
