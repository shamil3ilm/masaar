<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Networks\Peppol\Console;

use App\Domains\Compliance\Networks\Peppol\Sending\ReportRetrier;
use Illuminate\Console\Command;

/**
 * Ask the provider again about documents whose report to the authority
 * failed. Scheduled hourly; each document at most once an hour, for up to
 * ReportRetrier::WINDOW_DAYS days.
 */
class RetryReports extends Command
{
    protected $signature = 'peppol:retry-reports
                            {--limit=200 : At most this many per run}';

    protected $description = 'Ask the Peppol service provider to report failed reports to the authority again';

    public function handle(ReportRetrier $retrier): int
    {
        $documents = $retrier->due((int) $this->option('limit'));
        $asked = 0;

        foreach ($documents as $document) {
            $refusal = $retrier->retry((string) $document->id);

            if ($refusal === null) {
                $asked++;
            } else {
                $this->warn("Document {$document->id}: {$refusal}");
            }
        }

        $this->info("Asked again for {$asked} of {$documents->count()} reports.");

        return self::SUCCESS;
    }
}
