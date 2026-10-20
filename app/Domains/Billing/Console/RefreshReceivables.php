<?php

declare(strict_types=1);

namespace App\Domains\Billing\Console;

use App\Domains\Billing\Services\Receivables;
use Illuminate\Console\Command;

/**
 * Move the platform's invoices that fell due to overdue. Scheduled daily.
 */
class RefreshReceivables extends Command
{
    protected $signature = 'billing:refresh-receivables';

    protected $description = 'Mark the platform\'s unpaid invoices that fell due as overdue';

    public function handle(Receivables $receivables): int
    {
        $this->info('Moved '.$receivables->refresh().' invoices to their status for today.');

        return self::SUCCESS;
    }
}
