<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Networks\Peppol\Jobs;

use App\Domains\Compliance\Networks\Peppol\Sending\PeppolSender;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Sends one Peppol document to the service provider.
 *
 * On its own queue (PEPPOL_QUEUE, worked by laravel-worker-peppol), so a slow
 * provider never sits in front of other compliance work and the reverse.
 *
 * Carries the document id rather than the model: the row is re-read under
 * its lock by PeppolSender, which decides whether there is anything to send.
 * A provider with no verdict is asked again after the backoff, by releasing
 * the job rather than failing it, so the document's own retry window decides
 * when to stop and the worker's --tries does not.
 */
class SendPeppolDocument implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** Seconds before each next attempt; hourly after the last. */
    public const BACKOFF = [60, 300, 900, 3600];

    /**
     * Unlimited: the document's retry window, which an outage stops, decides
     * when to give up (PeppolSender::retryWindowHours), not a count.
     */
    public int $tries = 0;

    /** Above the gateway's connect and request timeouts together. */
    public int $timeout = 90;

    public function __construct(public readonly string $documentId)
    {
        $this->onQueue((string) config('peppol.asp.queue', 'peppol'));
    }

    public function handle(PeppolSender $sender): void
    {
        if ($sender->send($this->documentId)) {
            $index = min(max(0, $this->attempts() - 1), count(self::BACKOFF) - 1);

            $this->release(self::BACKOFF[$index]);
        }
    }

    /**
     * Out of tries, or an error nobody anticipated.
     */
    public function failed(Throwable $exception): void
    {
        app(PeppolSender::class)->giveUp($this->documentId, $exception->getMessage());
    }
}
