<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One chain entry per position, and one per document.
     *
     * `invoices` constrains (org_id, icv) to be unique, and the chain records
     * the same sequence — so without the same constraint here the record of
     * the chain can hold two entries at a position the chain itself cannot.
     * That is the one shape a comparison between them could never detect,
     * because both would agree at every position either of them has.
     *
     * The invoice_id unique is what makes ChainRecorder's updateOrCreate an
     * update: re-issuing a document replaces its entry rather than adding a
     * second one describing the same invoice differently.
     */
    public function up(): void
    {
        Schema::table('hash_chain_history', function (Blueprint $table) {
            $table->dropIndex('hash_chain_history_organization_id_icv_index');
            $table->unique(['org_id', 'icv'], 'hash_chain_history_org_icv_unique');
            $table->unique(['invoice_id'], 'hash_chain_history_invoice_unique');
        });
    }

    public function down(): void
    {
        Schema::table('hash_chain_history', function (Blueprint $table) {
            $table->dropUnique('hash_chain_history_invoice_unique');
            $table->dropUnique('hash_chain_history_org_icv_unique');
            $table->index(['org_id', 'icv'], 'hash_chain_history_organization_id_icv_index');
        });
    }
};
