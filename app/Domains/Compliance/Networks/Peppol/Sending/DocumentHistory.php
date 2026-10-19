<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Networks\Peppol\Sending;

use App\Domains\Compliance\Networks\Peppol\Gateways\AspStatus;
use App\Domains\Compliance\Networks\Peppol\Models\PeppolDocument;
use App\Domains\Compliance\Networks\Peppol\Models\PeppolDocumentEvent;
use Illuminate\Support\Collection;

/**
 * A document's transmission record: every move, callback and status lookup,
 * append-only (PeppolDocumentEvent), and the current state derived from it.
 *
 * The data on an e-invoice's issuance, transmission and receipt must be kept
 * (Electronic Invoicing Guidelines v1.1, p12), and the taxpayer stays
 * responsible for it (p47) - the provider keeps only its own logs. So each
 * word is kept as it came, applied or not: a late callback that moves
 * nothing is still evidence of what the provider said, and when.
 *
 * PeppolDocument's status columns are the projection of the applied events,
 * written in the same transaction; replay() derives them again, so the two
 * can be checked against each other.
 */
final class DocumentHistory
{
    public const SOURCE_SUBMISSION = 'submission';

    public const SOURCE_CALLBACK = 'callback';

    public const SOURCE_POLL = 'poll';

    public const SOURCE_LOOKUP = 'lookup';

    public const SOURCE_RETRY = 'retry';

    public const SOURCE_OPERATOR = 'operator';

    public const SOURCE_SYSTEM = 'system';

    /** The fields a move writes that the record keeps beside the status. */
    private const KEPT = ['mls_code', 'asp_message_id', 'authority_reference', 'reporting_status'];

    /**
     * A document came into being: its first state, both tracks at once.
     */
    public function created(PeppolDocument $document, string $source = self::SOURCE_SYSTEM): void
    {
        $this->write($document, PeppolDocumentEvent::TRACK_STATE, $source, true, [
            'status' => $document->status,
            'reporting_status' => $document->reporting_status,
            'mls_code' => $document->mls_code,
            'asp_message_id' => $document->asp_message_id,
            'authority_reference' => $document->authority_reference,
            'errors' => $document->errors,
        ]);
    }

    /**
     * A move the document made, with what was written with it.
     *
     * @param  array<string, mixed>  $written
     */
    public function moved(PeppolDocument $document, string $track, string $to, string $source, array $written, ?string $digest = null): void
    {
        $errors = $track === DocumentStatus::TRACK_REPORTING ? ($written['reporting_errors'] ?? null) : ($written['errors'] ?? null);

        $this->write($document, $track, $source, true, [
            ($track === DocumentStatus::TRACK_REPORTING ? 'reporting_status' : 'status') => $to,
            ...array_intersect_key($written, array_flip(self::KEPT)),
            'asp_message_id' => $document->asp_message_id,
            'errors' => $errors,
        ], $digest);
    }

    /**
     * What the provider said, when it moved nothing: repeated, late, or not
     * a status the platform tracks.
     */
    public function heard(PeppolDocument $document, AspStatus $update, string $source, ?string $digest): void
    {
        $reporting = $update->aboutReporting();

        $this->write($document, $reporting ? DocumentStatus::TRACK_REPORTING : DocumentStatus::TRACK_DELIVERY, $source, false, [
            ($reporting ? 'reporting_status' : 'status') => $update->status,
            'mls_code' => $update->mlsCode,
            'asp_message_id' => $update->messageId,
            'authority_reference' => $update->authorityReference,
            'errors' => $update->errors === [] ? null : $update->errors,
        ], $digest);
    }

    /**
     * @return Collection<int, PeppolDocumentEvent> oldest first
     */
    public function of(string $documentId): Collection
    {
        return PeppolDocumentEvent::query()->where('document_id', $documentId)->orderBy('id')->get();
    }

    /**
     * The state the applied events add up to, as PeppolDocument holds it.
     *
     * @return array{status: string|null, reporting_status: string|null, mls_code: string|null, asp_message_id: string|null, authority_reference: string|null}
     */
    public function replay(string $documentId): array
    {
        $state = ['status' => null, 'reporting_status' => null, 'mls_code' => null, 'asp_message_id' => null, 'authority_reference' => null];

        foreach ($this->of($documentId)->where('applied', true) as $event) {
            foreach ($state as $field => $value) {
                $state[$field] = $event->getAttribute($field) ?? $value;
            }
        }

        return $state;
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function write(PeppolDocument $document, string $track, string $source, bool $applied, array $fields, ?string $digest = null): void
    {
        PeppolDocumentEvent::query()->create([
            ...$fields,
            'document_id' => $document->id,
            'source' => $source,
            'track' => $track,
            'applied' => $applied,
            'payload_digest' => $digest,
            'occurred_at' => now(),
        ]);
    }
}
