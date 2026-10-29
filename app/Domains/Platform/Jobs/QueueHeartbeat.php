<?php

declare(strict_types=1);

namespace App\Domains\Platform\Jobs;

use App\Domains\Platform\Services\Heartbeat;
use App\Domains\Platform\Support\QueueConnections;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Sent down one queue every minute: the worker that picks it up records
 * that the queue is being worked (Heartbeat). On the connection the queue's
 * own jobs are on (QueueConnections): a worker of a queue moved to a
 * connection of its own never saw one sent to the default.
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
        $this->onConnection(QueueConnections::for($name));
    }

    public function handle(Heartbeat $heartbeat): void
    {
        $heartbeat->beat(Heartbeat::queue($this->name));
    }
}
