<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Console;

use App\Domains\Invoice\Services\ScheduleRunner;
use Illuminate\Console\Command;

/**
 * Make every schedule's due invoices. Scheduled every fifteen minutes, so a
 * period is invoiced soon after midnight on its organization's clock and a
 * failed run is tried again when it is due. Safe to run at any time and as
 * often as wanted: a period is invoiced once (ScheduleRunner).
 */
class RunSchedules extends Command
{
    protected $signature = 'invoices:run-schedules';

    protected $description = 'Make the invoices that schedules have due, and retry the ones that failed';

    public function handle(ScheduleRunner $runner): int
    {
        $this->info('Attempted '.$runner->runDue().' scheduled invoices.');

        return self::SUCCESS;
    }
}
