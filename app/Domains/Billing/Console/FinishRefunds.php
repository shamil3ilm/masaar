<?php

declare(strict_types=1);

namespace App\Domains\Billing\Console;

use App\Domains\Billing\Services\Refunds;
use Illuminate\Console\Command;

/**
 * Carry on the refunds a failure left half done: a credit note issued and
 * not yet credited, money sent to a card and not yet recorded, or an
 * amount held for a note a crash never reported - finished with the note
 * it issued, or released (Refunds::finishOpen). Scheduled every ten
 * minutes.
 */
class FinishRefunds extends Command
{
    protected $signature = 'billing:finish-refunds';

    protected $description = 'Finish refunds a failure left half done';

    public function handle(Refunds $refunds): int
    {
        $this->info('Finished '.$refunds->finishOpen().' refunds.');

        return self::SUCCESS;
    }
}
