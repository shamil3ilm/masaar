<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A provider's message id is unique per direction, not across both.
 *
 * One provider can give the same id to a document going out from one tenant
 * and coming in to another. A single unique index made the second of them
 * fail to store: a received invoice was lost, or a sent one stuck in
 * 'sending' with no id to be called back about.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('peppol_documents', function (Blueprint $table) {
            $table->dropUnique(['asp_message_id']);
            $table->unique(['direction', 'asp_message_id']);
        });
    }

    public function down(): void
    {
        Schema::table('peppol_documents', function (Blueprint $table) {
            $table->dropUnique(['direction', 'asp_message_id']);
            $table->unique(['asp_message_id']);
        });
    }
};
