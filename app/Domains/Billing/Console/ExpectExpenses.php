<?php

declare(strict_types=1);

namespace App\Domains\Billing\Console;

use App\Domains\Billing\Services\RecurringExpenses;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Draft the platform's recurring costs that fell due, and report the bills
 * expected and still not entered. Scheduled daily.
 */
class ExpectExpenses extends Command
{
    protected $signature = 'billing:expect-expenses';

    protected $description = 'Draft the platform\'s recurring costs that fell due and report bills not entered in time';

    public function handle(RecurringExpenses $recurring): int
    {
        $outcome = $recurring->run(CarbonImmutable::now()->startOfDay());

        $this->info('Drafted '.$outcome['drafted'].' expected bills; '.$outcome['missing'].' missing.');

        foreach ($outcome['failed'] as $failure) {
            $this->warn('Not drafted: '.$failure);
        }

        return self::SUCCESS;
    }
}
