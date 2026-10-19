<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Networks\Peppol\Console;

use App\Domains\Compliance\Networks\Peppol\Sending\PeppolSender;
use Illuminate\Console\Command;

/**
 * Settle documents left in 'sending' by a worker that died mid-call, by
 * looking each up with the provider under its own reference. Scheduled
 * every ten minutes.
 */
class RecoverSending extends Command
{
    protected $signature = 'peppol:recover-sending
                            {--minutes=15 : Only documents in sending at least this long}
                            {--limit=200 : At most this many per run}';

    protected $description = 'Look up Peppol documents stuck in sending with the service provider, by their own reference';

    public function handle(PeppolSender $sender): int
    {
        $documents = $sender->stuck((int) $this->option('minutes'), (int) $this->option('limit'));
        $outcomes = ['sent' => 0, 'requeued' => 0, 'unknown' => 0];

        foreach ($documents as $document) {
            $outcomes[$sender->recover((string) $document->id)]++;
        }

        $this->info("Stuck: {$documents->count()}. Found at the provider: {$outcomes['sent']}. Sent again: {$outcomes['requeued']}. Still unknown: {$outcomes['unknown']}.");

        return self::SUCCESS;
    }
}
