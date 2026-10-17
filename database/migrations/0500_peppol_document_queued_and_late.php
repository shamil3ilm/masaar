<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two facts about an outbound Peppol document that its status cannot hold.
 *
 * queued_at: when it was first handed to the send queue. An invoice issued
 * with auto_submit false is prepared but held back, and both look 'ready';
 * this tells a document never queued from one waiting in the queue, so an
 * explicit send queues it once and never twice. Every outbound document
 * stored before this was queued as it was made, so they take created_at.
 *
 * issued_late: issued more than 14 days after the business transaction
 * (MD 243/2025 Art. 6(5)). Late issuing is still issuing, so it is recorded,
 * not refused.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('peppol_documents', function (Blueprint $table) {
            $table->timestamp('queued_at')->nullable();
            $table->boolean('issued_late')->default(false);
        });

        DB::table('peppol_documents')
            ->where('direction', 'out')
            ->update(['queued_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('peppol_documents', function (Blueprint $table) {
            $table->dropColumn(['queued_at', 'issued_late']);
        });
    }
};
