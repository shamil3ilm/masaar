<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Networks\Peppol\Sending;

use App\Domains\Compliance\Networks\Peppol\Jobs\SendPeppolDocument;
use App\Domains\Compliance\Networks\Peppol\Models\PeppolDocument;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Whether the provider is down, judged by its answers.
 *
 * A provider that gives no verdict to several documents in a row is in an
 * outage, not refusing one document. The provider must tell the taxpayer
 * and the authority of a disruption, and exchange and report every pending
 * invoice once it is resolved (Electronic Invoicing Guidelines v1.1, p45-46).
 * So during an outage no document is given up on - its retry window stops
 * running - and when the provider answers again every waiting document is
 * sent at once rather than at its next back-off.
 *
 * Kept in the cache: it is the platform's reading of the provider now, not a
 * record. The operator sees it on the admin Peppol page and in the log.
 */
final class ProviderOutage
{
    /** No-verdict answers in a row, across documents, that make an outage. */
    public const THRESHOLD = 3;

    private const STATE = 'peppol:outage';

    private const FAILURES = 'peppol:outage:failures';

    /** Waiting documents queued at once when the provider is back. */
    private const RESUME_LIMIT = 1000;

    /**
     * The provider gave no verdict.
     */
    public function noAnswer(string $error): void
    {
        Cache::add(self::FAILURES, 0, now()->addDay());
        $failures = (int) Cache::increment(self::FAILURES);

        if ($failures < self::THRESHOLD || $this->current() !== null) {
            return;
        }

        Cache::forever(self::STATE, ['since' => now()->toIso8601String(), 'last_error' => mb_substr($error, 0, 500)]);

        Log::critical('The Peppol service provider is not answering: an outage. Documents wait and are sent when it is back; the provider must notify the authority of the disruption', [
            'failures' => $failures,
            'error' => $error,
        ]);
    }

    /**
     * The provider answered with a verdict: whatever outage there was is over.
     */
    public function answered(): void
    {
        Cache::forget(self::FAILURES);
        $outage = $this->current();

        if ($outage === null) {
            return;
        }

        Cache::forget(self::STATE);
        $resumed = $this->resume();

        Log::warning('The Peppol service provider is answering again; waiting documents are sent now', [
            'since' => $outage['since'],
            'queued' => $resumed,
        ]);
    }

    /**
     * @return array{since: string, last_error: string}|null
     */
    public function current(): ?array
    {
        $state = Cache::get(self::STATE);

        return is_array($state) ? $state : null;
    }

    public function active(): bool
    {
        return $this->current() !== null;
    }

    /**
     * Send every document waiting since its last unanswered attempt now.
     * One already being sent is claimed by its own job first; the second
     * finds it not ready and does nothing.
     */
    private function resume(): int
    {
        $waiting = PeppolDocument::acrossTenants()
            ->where('direction', PeppolDocument::DIRECTION_OUT)
            ->where('status', DocumentStatus::READY)
            ->whereNotNull('queued_at')
            ->where('attempts', '>', 0)
            ->orderBy('queued_at')
            ->limit(self::RESUME_LIMIT)
            ->pluck('id');

        foreach ($waiting as $id) {
            SendPeppolDocument::dispatch((string) $id)->afterCommit();
        }

        return $waiting->count();
    }
}
