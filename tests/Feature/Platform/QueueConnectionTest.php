<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Domains\Platform\Jobs\QueueHeartbeat;
use App\Domains\Platform\Support\QueueConnections;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Filing on a queue connection of its own is configuration alone
 * (ZATCA_QUEUE_CONNECTION): submissions and webhook deliveries go there, and
 * so does what watches them - the heartbeat their workers record, the depth
 * the metrics report. The heartbeat went to the default connection, where
 * no worker of those queues listens, and the preflight reported them dead.
 */
class QueueConnectionTest extends TestCase
{
    public function test_heartbeat_follows_the_filing_connection(): void
    {
        config(['fatoora.queue.connection' => 'redis']);
        Queue::fake();

        $this->artisan('platform:heartbeat', ['--queue' => ['default', 'zatca-submissions', 'webhooks']])->assertSuccessful();

        Queue::assertPushed(QueueHeartbeat::class, 3);
        foreach (['default' => null, 'zatca-submissions' => 'redis', 'webhooks' => 'redis'] as $queue => $connection) {
            Queue::assertPushed(QueueHeartbeat::class, fn (QueueHeartbeat $job): bool => $job->name === $queue && $job->connection === $connection);
        }
    }

    public function test_without_one_everything_stays_default(): void
    {
        config(['fatoora.queue.connection' => null]);

        $this->assertNull(QueueConnections::for('zatca-submissions'));
        $this->assertNull(QueueConnections::for('peppol'));
    }
}
