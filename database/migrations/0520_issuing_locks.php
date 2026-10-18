<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per chain a document can be issued into (IssuingLifecycle).
 *
 * Issuing holds its chain's row for the whole issuance - counter, number,
 * signature, chain entry - so documents of one chain are issued one at a
 * time and documents of different chains at once. The scope is the
 * jurisdiction's to name: a Saudi device, or the organization where a
 * country has no device chains.
 *
 * No foreign key, on purpose: a row locked here must not hold up anything
 * else, and a key to organizations would make every insert that checks it
 * wait on the row. A row whose organization is gone locks nothing anyone
 * asks for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('issuing_locks', function (Blueprint $table) {
            $table->uuid('org_id');
            $table->string('scope', 64);
            $table->timestamp('held_at')->nullable();

            $table->primary(['org_id', 'scope']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('issuing_locks');
    }
};
