<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Networks\Peppol\Console;

use App\Domains\Compliance\Networks\Peppol\Sending\PeppolSender;
use Illuminate\Console\Command;

/**
 * Send a failed outbound document again.
 *
 * A document fails when every attempt got no verdict from the provider -
 * wrong credentials or a long outage - and nothing retries it after that.
 * Run this once the cause is fixed.
 */
class Resend extends Command
{
    protected $signature = 'peppol:resend {document : The failed document\'s id}';

    protected $description = 'Queue a failed Peppol document to be sent again';

    public function handle(PeppolSender $sender): int
    {
        $id = (string) $this->argument('document');

        if (! $sender->resend($id)) {
            $this->error("Document {$id} is not a failed document, so it was not queued.");

            return self::FAILURE;
        }

        $this->info("Document {$id} is queued to be sent again.");

        return self::SUCCESS;
    }
}
