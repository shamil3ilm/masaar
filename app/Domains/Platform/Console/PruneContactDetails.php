<?php

declare(strict_types=1);

namespace App\Domains\Platform\Console;

use App\Domains\Platform\Website\ContactInbox;
use Illuminate\Console\Command;

/**
 * Clears the network address and browser kept with contact form messages
 * once they are older than config('website.contact.keep_details_days').
 * They were kept for spam review only; the messages themselves stay.
 */
class PruneContactDetails extends Command
{
    protected $signature = 'website:prune-contact-details';

    protected $description = 'Clear the network address and browser of old contact form messages';

    public function handle(ContactInbox $inbox): int
    {
        $this->info('Cleared the details of '.$inbox->pruneDetails().' contact messages.');

        return self::SUCCESS;
    }
}
