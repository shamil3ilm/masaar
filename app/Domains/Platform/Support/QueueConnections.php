<?php

declare(strict_types=1);

namespace App\Domains\Platform\Support;

/**
 * The queue connection each of the platform's queues lives on.
 *
 * Every queue is on the application's own connection (QUEUE_CONNECTION,
 * the database as shipped) unless the deployment moves filing to one of its
 * own: ZATCA_QUEUE_CONNECTION puts submissions and webhook deliveries there
 * - Redis, say, with a pool of workers that read nothing else - and the
 * rest stays where it was. What watches a queue asks here, so it watches the
 * connection the jobs are on.
 */
final class QueueConnections
{
    /** The connection $queue is on; null for the application's own. */
    public static function for(string $queue): ?string
    {
        $connection = (string) config('fatoora.queue.connection');

        if ($connection === '' || ! in_array($queue, self::filing(), true)) {
            return null;
        }

        return $connection;
    }

    /**
     * The queues filing uses, by their configured names.
     *
     * @return list<string>
     */
    public static function filing(): array
    {
        return [
            (string) config('fatoora.queue.name', 'zatca-submissions'),
            (string) config('fatoora.queue.webhooks_queue', 'webhooks'),
        ];
    }
}
