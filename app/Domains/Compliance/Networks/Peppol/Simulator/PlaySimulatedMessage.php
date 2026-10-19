<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Networks\Peppol\Simulator;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * The simulated provider's next callback about one message.
 *
 * One callback per run, then the next is queued a step later, so they
 * arrive in order and as far apart as peppol.simulator.step_seconds says.
 * A callback that is not taken is tried again as a provider would:
 *
 *   2xx             taken; on to the next
 *   404 (status)    the platform does not know the message yet - its answer
 *                   to the submission may still be on the way - so later
 *   404 (inbound)   no organization here receives at that endpoint: final
 *   401, 422        the signature or body was refused: a fault to fix, not
 *                   to retry, and logged as one
 *   0, 429, 5xx     later
 *
 * Out of retries it stops; peppol:poll-status still finds the status
 * through SimulatedAspGateway::status().
 */
class PlaySimulatedMessage implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** Seconds before each retry of a callback that was not taken. */
    public const RETRY_AFTER = [5, 15, 60, 300, 900];

    public int $tries = 1;

    public function __construct(
        public readonly string $messageId,
        public readonly int $retry = 0,
    ) {
        $this->onQueue((string) config('peppol.asp.queue', 'peppol'));
    }

    /**
     * Queue the message's next callback, $seconds from now.
     */
    public static function after(string $messageId, int $seconds, int $retry = 0): void
    {
        $job = self::dispatch($messageId, $retry);

        if ($seconds > 0) {
            $job->delay($seconds);
        }
    }

    public function handle(SimulatorLedger $ledger, SimulatorCallbacks $callbacks): void
    {
        $message = $ledger->find($this->messageId);
        $event = $message?->nextEvent();

        if ($message === null || $event === null) {
            return;
        }

        $answer = $callbacks->deliver($message, $event);
        $inbound = $event['type'] === 'inbound';

        if (($answer >= 200 && $answer < 300) || ($inbound && $answer === 404)) {
            $played = $message->played();
            $ledger->save($played);

            if ($played->nextEvent() !== null) {
                self::after($this->messageId, (int) config('peppol.simulator.step_seconds', 0));
            }

            return;
        }

        if (in_array($answer, [401, 403, 422], true)) {
            Log::error('The platform refused a simulated provider callback; check PEPPOL_SIMULATOR_CALLBACK_URL and the webhook secret', [
                'message_id' => $this->messageId,
                'event' => $event['type'],
                'http_status' => $answer,
            ]);

            return;
        }

        if ($this->retry >= count(self::RETRY_AFTER)) {
            Log::warning('A simulated provider callback was never taken; the status poll will find it', [
                'message_id' => $this->messageId,
                'http_status' => $answer,
            ]);

            return;
        }

        self::after($this->messageId, self::RETRY_AFTER[$this->retry], $this->retry + 1);
    }
}
