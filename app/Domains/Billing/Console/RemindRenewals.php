<?php

declare(strict_types=1);

namespace App\Domains\Billing\Console;

use App\Domains\Billing\Services\RenewalReminders;
use Illuminate\Console\Command;

/**
 * Email organizations whose plan ends soon. Scheduled daily.
 */
class RemindRenewals extends Command
{
    protected $signature = 'billing:remind-renewals';

    protected $description = 'Email the admins of organizations whose plan ends soon';

    public function handle(RenewalReminders $reminders): int
    {
        $this->info('Sent '.$reminders->send().' renewal reminders.');

        return self::SUCCESS;
    }
}
