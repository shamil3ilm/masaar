<?php

declare(strict_types=1);

namespace App\Domains\Platform\Jobs;

use App\Domains\Platform\Services\Heartbeat;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Sent down one queue every minute: the worker that picks it up records
 * that the queue is being worked (Heartbeat). On the default connection,
 * which is where every queue the platform names lives unless a deployment
 * moves one.
 *
 * Tried once: the next minute sends another.
 */
final class QueueHeartbeat implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public string $name,
    ) {
        $this->onQueue($name);
    }

    public function handle(Heartbeat $heartbeat): void
    {
        $heartbeat->beat(Heartbeat::queue($this->name));
    }
}
