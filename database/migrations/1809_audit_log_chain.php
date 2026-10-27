<?php

declare(strict_types=1);

use App\Domains\Audit\Services\AuditChain;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The audit log becomes a hash chain per organization (AuditChain): each
 * entry's place, its predecessor's hash and its own. audit_chain_heads keeps
 * each chain's last entry and is the row a new entry locks to take its place.
 *
 * Entries written before this are chained here, oldest first, so the chain
 * starts at the first entry rather than at this migration. That seals them as
 * they stand today: it cannot prove they were not changed before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->string('chain', 36)->nullable();
            $table->unsignedBigInteger('chain_seq')->nullable();
            $table->char('previous_entry_hash', 64)->nullable();
            $table->char('entry_hash', 64)->nullable();
        });

        Schema::create('audit_chain_heads', function (Blueprint $table) {
            $table->string('chain', 36)->primary();
            $table->unsignedBigInteger('last_seq');
            $table->char('last_hash', 64);
        });

        $heads = [];

        foreach (DB::table('audit_logs')->orderBy('created_at')->orderBy('id')->lazy(1000) as $row) {
            $chain = AuditChain::chainOf($row->org_id);
            [$seq, $previous] = $heads[$chain] ?? [0, AuditChain::GENESIS];

            $placed = [...(array) $row, 'chain' => $chain, 'chain_seq' => $seq + 1];
            $hash = AuditChain::digest($placed, $previous);

            DB::table('audit_logs')->where('id', $row->id)->update([
                'chain' => $chain,
                'chain_seq' => $seq + 1,
                'previous_entry_hash' => $previous,
                'entry_hash' => $hash,
            ]);

            $heads[$chain] = [$seq + 1, $hash];
        }

        foreach ($heads as $chain => [$seq, $hash]) {
            DB::table('audit_chain_heads')->insert(['chain' => $chain, 'last_seq' => $seq, 'last_hash' => $hash]);
        }

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->unique(['chain', 'chain_seq'], 'audit_logs_chain_seq_unique');
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropUnique('audit_logs_chain_seq_unique');
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropColumn(['chain', 'chain_seq', 'previous_entry_hash', 'entry_hash']);
        });

        Schema::dropIfExists('audit_chain_heads');
    }
};
