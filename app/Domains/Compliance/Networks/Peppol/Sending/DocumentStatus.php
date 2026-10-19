<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Networks\Peppol\Sending;

use App\Domains\Compliance\Networks\Peppol\DTOs\AspError;
use App\Domains\Compliance\Networks\Peppol\Gateways\AspStatus;
use App\Domains\Compliance\Networks\Peppol\Models\PeppolDocument;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Where a document is, on two tracks, and the only thing that moves it.
 *
 * The provider delivers a document to the buyer's provider and reports its
 * tax data to the authority in parallel, and confirms each on its own (the
 * Electronic Invoicing Guidelines v1.1, p10 steps 4, 5 and 8; p11 step 10;
 * a taxpayer must hear of the success or failure of both, p41). So a
 * document has two statuses, and neither waits for or hides the other.
 *
 * Delivery (status):
 *
 *   ready     -> sending     claimed for one attempt
 *             -> failed      the queue gave up on it
 *   sending   -> sent        the provider took it (asp_message_id)
 *             -> not_exchanged  taken for a report-only endpoint: reported,
 *                            never delivered, done once reported
 *             -> ready       no verdict, tried again
 *             -> rejected    the provider refused it
 *             -> failed      no verdict, out of time
 *   sent      -> delivered   the buyer's provider accepted it (MLS AB/AP)
 *             -> rejected    the buyer's provider refused it (MLS RE)
 *   failed    -> ready       an operator sends it again
 *
 * Reporting (reporting_status), from the moment the provider takes it:
 *
 *   pending        -> reported        the authority confirmed the tax data
 *                  -> report_failed   it did not reach the authority
 *   report_failed  -> pending         asked again (ReportRetrier)
 *                  -> reported        the provider's own retry got through
 *
 * Callbacks arrive late, twice and out of order, so a status never moves
 * backwards on its track, and a status on one track never touches the
 * other: an early report confirmation leaves a later delivery to be
 * recorded, and a delivery notice says nothing about the report.
 *
 * Every transition re-reads the row under a lock and checks the move there,
 * so two workers or a worker and a callback cannot both make one. Each move,
 * and each word from the provider that moves nothing, goes on the
 * document's append-only record (DocumentHistory) in the same transaction.
 *
 * Inbound documents are 'received' and never move.
 */
final class DocumentStatus
{
    public const READY = 'ready';

    public const SENDING = 'sending';

    public const SENT = 'sent';

    public const DELIVERED = 'delivered';

    public const REJECTED = 'rejected';

    public const FAILED = 'failed';

    public const RECEIVED = 'received';

    /**
     * Taken for a reserved, report-only endpoint (ReportOnlyEndpoints): there
     * is no buyer's provider, so no delivery confirmation ever comes. The
     * document is done once reported (Electronic Invoicing Guidelines v1.1,
     * p23, p26: no exchange, only reporting; p28).
     */
    public const NOT_EXCHANGED = 'not_exchanged';

    /** Reporting: handed to the provider, the authority's answer still to come. */
    public const PENDING = 'pending';

    /** Reporting: the authority confirmed the tax data. */
    public const REPORTED = 'reported';

    /** Reporting: the tax data did not reach the authority. */
    public const REPORT_FAILED = 'report_failed';

    public const TRACK_DELIVERY = 'delivery';

    public const TRACK_REPORTING = 'reporting';

    private const DELIVERY = [
        self::READY => [self::SENDING, self::FAILED],
        self::SENDING => [self::READY, self::SENT, self::NOT_EXCHANGED, self::REJECTED, self::FAILED],
        self::SENT => [self::DELIVERED, self::REJECTED],
        // Back to ready only by an operator (peppol:resend), once whatever
        // stopped every attempt - credentials, an outage - is fixed.
        self::FAILED => [self::READY],
    ];

    private const REPORTING = [
        // A document the provider has taken is pending (PeppolSender); the
        // authority's answer can still be the first word on one that is not.
        '' => [self::PENDING, self::REPORTED, self::REPORT_FAILED],
        self::PENDING => [self::REPORTED, self::REPORT_FAILED],
        self::REPORT_FAILED => [self::PENDING, self::REPORTED],
    ];

    public function __construct(
        private readonly StatusAnnouncer $announcer,
        private readonly DocumentHistory $history,
    ) {}

    /**
     * Sent documents with an answer still to come, oldest first: missed
     * callbacks are caught by asking. A report-only document waits only for
     * its report, and is not asked about once reported.
     *
     * Up to a fortnight. Past that the provider has long answered or lost it,
     * and asking every ten minutes for ever would not change which.
     *
     * @return Collection<int, PeppolDocument>
     */
    public function awaitingAnswer(int $olderThanMinutes, int $limit): Collection
    {
        return PeppolDocument::withoutTenantScope(fn () => PeppolDocument::query()
            ->where('direction', PeppolDocument::DIRECTION_OUT)
            ->whereNotNull('asp_message_id')
            ->where(fn ($query) => $query
                ->where('status', self::SENT)
                ->orWhere('reporting_status', self::PENDING))
            ->where('updated_at', '<=', now()->subMinutes($olderThanMinutes))
            ->where('updated_at', '>=', now()->subDays(14))
            ->orderBy('updated_at')
            ->limit($limit)
            ->get());
    }

    public function allows(string $from, string $to): bool
    {
        return in_array($to, self::DELIVERY[$from] ?? [], true);
    }

    public function allowsReporting(?string $from, string $to): bool
    {
        return in_array($to, self::REPORTING[(string) $from] ?? [], true);
    }

    /**
     * Move a document's delivery status, or leave it as it is when the move
     * is not allowed.
     *
     * The attributes are written with the move; a closure computes them from
     * the locked row, so a counter cannot be incremented from a stale copy.
     *
     * @param  array<string, mixed>|Closure(PeppolDocument): array<string, mixed>  $attributes
     * @param  string|null  $from  move only from this status, when an allowed move is still the wrong one
     * @param  string  $source  what made the move, for the record (DocumentHistory::SOURCE_*)
     * @return PeppolDocument|null the moved document, or null when it did not move
     */
    public function transition(string $documentId, string $to, array|Closure $attributes = [], ?string $from = null, string $source = DocumentHistory::SOURCE_SYSTEM): ?PeppolDocument
    {
        return $this->move($documentId, self::TRACK_DELIVERY, $to, $attributes, $from, $source);
    }

    /**
     * Move a document's reporting status, as transition() moves its delivery.
     *
     * @param  array<string, mixed>|Closure(PeppolDocument): array<string, mixed>  $attributes
     */
    public function transitionReporting(string $documentId, string $to, array|Closure $attributes = [], ?string $from = null, string $source = DocumentHistory::SOURCE_SYSTEM): ?PeppolDocument
    {
        return $this->move($documentId, self::TRACK_REPORTING, $to, $attributes, $from, $source);
    }

    /**
     * Apply what the provider said about a sent document, on the track it
     * is about, and keep it on the record whether it moved the document or not.
     *
     * @param  string  $source  a callback, or a status lookup's answer
     * @param  string|null  $digest  SHA-256 of the body as it arrived
     * @return bool whether the message is one of ours; a status that changes
     *              nothing still is
     */
    public function apply(AspStatus $update, string $source = DocumentHistory::SOURCE_CALLBACK, ?string $digest = null): bool
    {
        $document = PeppolDocument::withoutTenantScope(fn () => PeppolDocument::query()
            ->where('asp_message_id', $update->messageId)
            ->where('direction', PeppolDocument::DIRECTION_OUT)
            ->first());

        if ($document === null) {
            return false;
        }

        $reporting = $update->aboutReporting();
        $current = $reporting ? $document->reporting_status : $document->status;

        if ($update->status === null || $update->status === $current) {
            $this->history->heard($document, $update, $source, $digest);

            return true;
        }

        $moved = $reporting
            ? $this->move((string) $document->id, self::TRACK_REPORTING, $update->status, array_filter([
                'reporting_errors' => $update->errors === [] ? null : AspError::toArrays($update->errors),
                'authority_reference' => $update->authorityReference,
            ], fn ($value) => $value !== null), null, $source, $digest)
            : $this->move((string) $document->id, self::TRACK_DELIVERY, $update->status, array_filter([
                'mls_code' => $update->mlsCode,
                'errors' => $update->errors === [] ? null : AspError::toArrays($update->errors),
            ], fn ($value) => $value !== null), null, $source, $digest);

        if ($moved === null) {
            $this->history->heard($document, $update, $source, $digest);

            Log::info('Peppol status not applied: the document has moved past it', [
                'document_id' => $document->id,
                'track' => $reporting ? self::TRACK_REPORTING : self::TRACK_DELIVERY,
                'from' => $current,
                'to' => $update->status,
            ]);
        }

        return true;
    }

    /**
     * @param  array<string, mixed>|Closure(PeppolDocument): array<string, mixed>  $attributes
     */
    private function move(string $documentId, string $track, string $to, array|Closure $attributes, ?string $from, string $source, ?string $digest = null): ?PeppolDocument
    {
        $column = $track === self::TRACK_REPORTING ? 'reporting_status' : 'status';

        $moved = DB::transaction(function () use ($documentId, $track, $column, $to, $attributes, $from, $source, $digest): ?PeppolDocument {
            $document = PeppolDocument::withoutTenantScope(fn () => PeppolDocument::query()
                ->whereKey($documentId)
                ->lockForUpdate()
                ->first());

            if ($document === null) {
                return null;
            }

            $current = $document->getAttribute($column);
            $allowed = $track === self::TRACK_REPORTING
                ? $this->allowsReporting($current, $to)
                : $this->allows((string) $current, $to);

            if (! $allowed || ($from !== null && $current !== $from)) {
                return null;
            }

            $values = $attributes instanceof Closure ? $attributes($document) : $attributes;
            $changes = [...$values, $column => $to];

            if ($track === self::TRACK_REPORTING) {
                $changes['reporting_changed_at'] = now();
            }

            $document->forceFill($changes)->save();
            $this->history->moved($document, $track, $to, $source, $changes, $digest);

            return $document;
        });

        if ($moved !== null) {
            $this->announcer->moved($moved, $track, $to);
        }

        return $moved;
    }
}
