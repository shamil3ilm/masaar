<?php

declare(strict_types=1);

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Webhook signing secrets, encrypted at rest.
 *
 * A receiver trusts a delivery by its HMAC, so the secret in the clear let
 * anyone holding a copy of the database or a backup forge events to a
 * customer's ERP. The model now casts it encrypted; the column grows to hold
 * the ciphertext, and the secrets already stored are encrypted in place. A
 * value that already decrypts is left alone, so running it again changes
 * nothing. Down decrypts them back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webhooks', function (Blueprint $table) {
            $table->text('secret')->change();
        });

        DB::table('webhooks')->select(['id', 'secret'])->orderBy('id')->chunk(500, function ($rows): void {
            foreach ($rows as $row) {
                try {
                    Crypt::decryptString((string) $row->secret);

                    continue;
                } catch (DecryptException) {
                    DB::table('webhooks')->where('id', $row->id)->update(['secret' => Crypt::encryptString((string) $row->secret)]);
                }
            }
        });
    }

    public function down(): void
    {
        DB::table('webhooks')->select(['id', 'secret'])->orderBy('id')->chunk(500, function ($rows): void {
            foreach ($rows as $row) {
                try {
                    DB::table('webhooks')->where('id', $row->id)->update(['secret' => Crypt::decryptString((string) $row->secret)]);
                } catch (DecryptException) {
                    continue;
                }
            }
        });

        Schema::table('webhooks', function (Blueprint $table) {
            $table->string('secret', 128)->change();
        });
    }
};
