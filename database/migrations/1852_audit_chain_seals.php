<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A keyed seal beside each audit entry's hash and each chain's head
 * (AuditChain::seal), made with AUDIT_CHAIN_KEY. The plain hash proves the
 * chain is whole to anyone; the seal proves it was not rewritten by someone
 * who could recompute those hashes, because that takes a key the database
 * does not hold.
 *
 * Null on what came before: entries written without a key still verify by
 * their hash, and from a chain's first sealed entry every later one must be
 * sealed (platform:verify-audit-chain).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->char('entry_mac', 64)->nullable();
        });

        Schema::table('audit_chain_heads', function (Blueprint $table) {
            $table->char('last_mac', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropColumn('entry_mac');
        });

        Schema::table('audit_chain_heads', function (Blueprint $table) {
            $table->dropColumn('last_mac');
        });
    }
};
