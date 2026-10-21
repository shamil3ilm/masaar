<?php

declare(strict_types=1);

namespace App\Domains\Platform\Console;

use App\Domains\Platform\Jobs\QueueHeartbeat;
use App\Domains\Platform\Services\Heartbeat;
use Illuminate\Console\Command;

/**
 * The scheduler's beat, and one sent down each queue for its worker to
 * record. Scheduled every minute (routes/console.php); read by
 * platform:simulation-preflight.
 */
class RecordHeartbeat extends Command
{
    protected $signature = 'platform:heartbeat
                            {--queue=* : A queue whose worker should record a beat}';

    protected $description = 'Record that the scheduler ran, and ask each queue\'s worker to record a beat';

    public function handle(Heartbeat $heartbeat): int
    {
        $heartbeat->beat(Heartbeat::SCHEDULER);

        foreach (array_unique(array_filter((array) $this->option('queue'))) as $queue) {
            QueueHeartbeat::dispatch((string) $queue);
        }

        return self::SUCCESS;
    }
}
