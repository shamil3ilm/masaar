<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every word about a Peppol document's transmission, kept as it came.
 *
 * Data on the issuance, transmission and receipt of each e-invoice must be
 * retained for five years or more (UAE Electronic Invoicing Guidelines v1.1,
 * p12), and the taxpayer stays responsible for it (p47). peppol_documents
 * holds the current state, which each callback overwrites; this keeps each
 * callback, status lookup and move that led there, append-only (class d):
 * an event time and created_at, never updated, never deleted.
 *
 * Existing documents get their current state as one event.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('peppol_document_events', function (Blueprint $table) {
            // A sequence, not a uuid: the order of the record is the order
            // things happened in, even within one second.
            $table->id();
            $table->uuid('document_id');
            // Where it came from: submission, callback, poll, lookup, retry,
            // operator, system, backfill.
            $table->string('source', 20);
            // delivery, reporting, or state: both at once (creation, backfill).
            $table->string('track', 20);
            // What the event says each track is now, where it says.
            $table->string('status', 20)->nullable();
            $table->string('reporting_status', 20)->nullable();
            // Whether it moved the document; a late or repeated word does not.
            $table->boolean('applied')->default(false);
            $table->string('mls_code', 20)->nullable();
            $table->string('asp_message_id')->nullable();
            $table->string('authority_reference')->nullable();
            $table->json('errors')->nullable();
            // SHA-256 of the body as received, so the record can be matched
            // to the provider's own without storing it twice.
            $table->char('payload_digest', 64)->nullable();
            $table->string('request_id', 64)->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->nullable();

            $table->index(['document_id', 'occurred_at']);
            $table->foreign('document_id')->references('id')->on('peppol_documents')->restrictOnDelete();
        });

        $now = now();

        DB::table('peppol_documents')->orderBy('created_at')->orderBy('id')->chunk(500, function ($documents) use ($now): void {
            DB::table('peppol_document_events')->insert($documents->map(fn ($document): array => [
                'document_id' => $document->id,
                'source' => 'backfill',
                'track' => 'state',
                'status' => $document->status,
                'reporting_status' => $document->reporting_status,
                'applied' => true,
                'mls_code' => $document->mls_code,
                'asp_message_id' => $document->asp_message_id,
                'authority_reference' => $document->authority_reference,
                'errors' => $document->errors,
                'payload_digest' => null,
                'request_id' => null,
                'occurred_at' => $document->updated_at ?? $document->created_at ?? $now,
                'created_at' => $now,
            ])->all());
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('peppol_document_events');
    }
};
