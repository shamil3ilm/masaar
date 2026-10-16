<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * People managing their own accounts.
 *
 * A new email address waits beside the current one until a link sent to it is
 * opened, so a mistyped address cannot lock its owner out. An organization
 * can require its people to use a second factor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('pending_email')->nullable();
            $table->string('pending_email_hash', 64)->nullable()->unique();
            $table->timestamp('pending_email_expires_at')->nullable();
            $table->timestamp('anonymized_at')->nullable();
        });

        Schema::table('organizations', function (Blueprint $table) {
            $table->boolean('require_two_factor')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('require_two_factor');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['pending_email_hash']);
            $table->dropColumn(['pending_email', 'pending_email_hash', 'pending_email_expires_at', 'anonymized_at']);
        });
    }
};
