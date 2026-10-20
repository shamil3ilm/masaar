<?php

declare(strict_types=1);

namespace App\Domains\Billing\Console;

use App\Domains\Billing\Services\ReceivableReminders;
use Illuminate\Console\Command;

/**
 * Remind customers of the platform's invoices on their reminder days, before
 * and after the due date. Scheduled daily.
 */
class RemindReceivables extends Command
{
    protected $signature = 'billing:remind-receivables';

    protected $description = 'Email customers whose invoices from the platform fall due soon or are past due';

    public function handle(ReceivableReminders $reminders): int
    {
        $this->info('Sent '.$reminders->send().' invoice reminders.');

        return self::SUCCESS;
    }
}
