<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Widens the previous-invoice-hash column to hold the figure ZATCA asks for.
 *
 * A PIH is normally the previous document's hash, which is the base64 of a
 * SHA-256 digest's thirty-two bytes and so forty-four characters. The first
 * document in a chain carries ZATCA's genesis value instead, and that one is
 * the base64 of the digest written as hex - eighty-eight characters, which the
 * sixty-four this column held cannot take.
 *
 * SQLite ignores a varchar's length, so the suite accepted the value and MySQL
 * refused it: every chain entry failed to write, which is every submission.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hash_chain_history', function (Blueprint $table) {
            $table->string('previous_hash', 255)->change();
        });
    }

    public function down(): void
    {
        Schema::table('hash_chain_history', function (Blueprint $table) {
            $table->string('previous_hash', 64)->change();
        });
    }
};
