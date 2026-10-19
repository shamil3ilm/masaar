<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A Peppol document's own UUID, and the provider's id for it.
 *
 * Every e-invoice carries a UUID that makes it unique and prevents its
 * duplication (UAE Electronic Invoicing Guidelines v1.1, p11 row 7). It was
 * the invoice's database id; it is now generated for the document and kept
 * here, indexed, beside the provider's own id for the document, which is a
 * reference. Not unique: a received document's UUID is whatever its
 * supplier wrote, and two from a careless supplier must both be kept.
 *
 * Existing rows: a sent document's UUID is the one its XML carries, the
 * invoice's id; a received one's is read from its XML.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('peppol_documents', function (Blueprint $table) {
            $table->string('document_uuid', 36)->nullable();
            $table->string('provider_reference')->nullable();
            $table->index(['direction', 'document_uuid']);
        });

        DB::table('peppol_documents')
            ->where('direction', 'out')
            ->whereNotNull('invoice_id')
            ->update(['document_uuid' => DB::raw('invoice_id')]);

        DB::table('peppol_documents')->where('direction', 'in')->orderBy('id')->chunk(200, function ($documents): void {
            foreach ($documents as $document) {
                if (preg_match('#<cbc:UUID>\s*([0-9A-Fa-f-]{36})\s*</cbc:UUID>#', (string) $document->xml, $match) === 1) {
                    DB::table('peppol_documents')->where('id', $document->id)->update(['document_uuid' => $match[1]]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('peppol_documents', function (Blueprint $table) {
            $table->dropIndex(['direction', 'document_uuid']);
            $table->dropColumn(['document_uuid', 'provider_reference']);
        });
    }
};
