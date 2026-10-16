<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Peppol\Services;

use App\Domains\Compliance\Peppol\Gateways\AspStatus;
use App\Domains\Compliance\Peppol\Models\PeppolDocument;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Where a document is on its way to the buyer, and the only thing that moves it.
 *
 *   ready     -> sending     claimed for one attempt
 *             -> failed      the queue gave up on it
 *   sending   -> sent        the provider took it (asp_message_id)
 *             -> ready       no verdict, tried again
 *             -> rejected    the provider refused it
 *             -> failed      no verdict, out of attempts
 *   sent      -> delivered   the buyer's provider accepted it (MLS AB/AP)
 *             -> rejected    the buyer's provider refused it (MLS RE)
 *             -> reported    the authority's confirmation came first
 *   delivered -> reported
 *   reported  -> rejected    see below
 *
 * Callbacks arrive late, twice and out of order, so a status never moves
 * backwards: a delivery notice after the authority's confirmation changes
 * nothing. The one move past 'reported' is to 'rejected': the provider
 * reports to the authority in parallel with the buyer's validation, so the
 * confirmation can arrive first, and a later refusal by the buyer's provider
 * is still the outcome the seller must act on.
 *
 * Every transition re-reads the row under a lock and checks the move there,
 * so two workers or a worker and a callback cannot both make one.
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

    public const REPORTED = 'reported';

    public const FAILED = 'failed';

    public const RECEIVED = 'received';

    private const TRANSITIONS = [
        self::READY => [self::SENDING, self::FAILED],
        self::SENDING => [self::READY, self::SENT, self::REJECTED, self::FAILED],
        self::SENT => [self::DELIVERED, self::REJECTED, self::REPORTED],
        self::DELIVERED => [self::REPORTED],
        self::REPORTED => [self::REJECTED],
    ];

    /**
     * Sent documents nobody called back about, oldest first: missed callbacks
     * are caught by asking.
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
            ->where('status', self::SENT)
            ->whereNotNull('asp_message_id')
            ->where('updated_at', '<=', now()->subMinutes($olderThanMinutes))
            ->where('updated_at', '>=', now()->subDays(14))
            ->orderBy('updated_at')
            ->limit($limit)
            ->get());
    }

    public function allows(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    /**
     * Move a document, or leave it as it is when the move is not allowed.
     *
     * The attributes are written with the move; a closure computes them from
     * the locked row, so a counter cannot be incremented from a stale copy.
     *
     * @param  array<string, mixed>|Closure(PeppolDocument): array<string, mixed>  $attributes
     * @param  string|null  $from  move only from this status, when an allowed move is still the wrong one
     * @return PeppolDocument|null the moved document, or null when it did not move
     */
    public function transition(string $documentId, string $to, array|Closure $attributes = [], ?string $from = null): ?PeppolDocument
    {
        return DB::transaction(function () use ($documentId, $to, $attributes, $from): ?PeppolDocument {
            $document = PeppolDocument::withoutTenantScope(fn () => PeppolDocument::query()
                ->whereKey($documentId)
                ->lockForUpdate()
                ->first());

            if ($document === null || ! $this->allows((string) $document->status, $to)) {
                return null;
            }

            if ($from !== null && $document->status !== $from) {
                return null;
            }

            $values = $attributes instanceof Closure ? $attributes($document) : $attributes;

            $document->forceFill([...$values, 'status' => $to])->save();

            return $document;
        });
    }

    /**
     * Apply what the provider said about a sent document.
     *
     * @return bool whether the message is one of ours; a status that changes
     *              nothing still is
     */
    public function apply(AspStatus $update): bool
    {
        $document = PeppolDocument::withoutTenantScope(fn () => PeppolDocument::query()
            ->where('asp_message_id', $update->messageId)
            ->where('direction', PeppolDocument::DIRECTION_OUT)
            ->first());

        if ($document === null) {
            return false;
        }

        if ($update->status === null || $update->status === $document->status) {
            return true;
        }

        $moved = $this->transition((string) $document->id, $update->status, array_filter([
            'mls_code' => $update->mlsCode,
            'errors' => $update->errors === [] ? null : $update->errors,
        ], fn ($value) => $value !== null));

        if ($moved === null) {
            Log::info('Peppol status not applied: the document has moved past it', [
                'document_id' => $document->id,
                'from' => $document->status,
                'to' => $update->status,
            ]);
        }

        return true;
    }
}
