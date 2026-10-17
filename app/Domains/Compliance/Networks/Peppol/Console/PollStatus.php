<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Networks\Peppol\Console;

use App\Domains\Compliance\Networks\Peppol\Contracts\AspGateway;
use App\Domains\Compliance\Networks\Peppol\Exceptions\AspUnavailableException;
use App\Domains\Compliance\Networks\Peppol\Exceptions\NoAspConfiguredException;
use App\Domains\Compliance\Networks\Peppol\Sending\DocumentStatus;
use Illuminate\Console\Command;

/**
 * Ask the provider about sent documents nobody called back about.
 *
 * Callbacks are the normal path; this is the fallback for the ones lost to
 * an outage on either side. Scheduled every ten minutes.
 */
class PollStatus extends Command
{
    protected $signature = 'peppol:poll-status
                            {--minutes=30 : Only documents sent at least this long ago}
                            {--limit=200 : At most this many per run}';

    protected $description = 'Ask the Peppol service provider what became of sent documents';

    public function handle(AspGateway $gateway, DocumentStatus $status): int
    {
        $documents = $status->awaitingAnswer((int) $this->option('minutes'), (int) $this->option('limit'));
        $asked = 0;

        foreach ($documents as $document) {
            try {
                $status->apply($gateway->status((string) $document->asp_message_id));
                $asked++;
            } catch (NoAspConfiguredException) {
                $this->warn('No service provider is configured; nothing to ask.');

                return self::SUCCESS;
            } catch (AspUnavailableException $e) {
                // The next run asks again; the status stands meanwhile.
                $this->warn("Document {$document->id}: {$e->getMessage()}");
            }
        }

        $this->info("Asked about {$asked} of {$documents->count()} documents.");

        return self::SUCCESS;
    }
}
