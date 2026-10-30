<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\UnitedArabEmirates\Console;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Compliance\Countries\UnitedArabEmirates\Reports\ArchiveDownload;
use App\Domains\Compliance\Countries\UnitedArabEmirates\Reports\DocumentArchive;
use App\Domains\Compliance\Countries\UnitedArabEmirates\Support\EinvoicingStart;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * A UAE organization's issued e-invoices for a period as one ZIP on disk
 * (DocumentArchive): each PINT AE document as sent, the PDFs, a manifest
 * and SHA256SUMS. No limit on the period: it is written a page at a time.
 */
class ExportArchive extends Command
{
    protected $signature = 'uae:export-archive
                            {organization : The organization\'s id}
                            {--from= : First issue date, YYYY-MM-DD}
                            {--to= : Last issue date, YYYY-MM-DD}
                            {--path= : Where to write the ZIP}
                            {--without-pdf : Leave the PDFs out}';

    protected $description = 'Write a period\'s issued UAE e-invoices, a manifest and checksums to a ZIP';

    public function handle(DocumentArchive $archive, EinvoicingStart $organizations, AuditService $audit): int
    {
        $organization = $organizations->organization((string) $this->argument('organization'));

        if ($organization === null) {
            $this->error('No such UAE organization.');

            return self::FAILURE;
        }

        try {
            $from = CarbonImmutable::createFromFormat('!Y-m-d', (string) $this->option('from'));
            $to = CarbonImmutable::createFromFormat('!Y-m-d', (string) $this->option('to'));
        } catch (Throwable) {
            $from = $to = false;
        }

        if ($from === false || $to === false || $to->lt($from)) {
            $this->error('Give --from and --to as YYYY-MM-DD, --to not before --from.');

            return self::FAILURE;
        }

        $path = (string) ($this->option('path') ?: storage_path("app/archives/{$archive->trn($organization)}_{$from->format('Ymd')}-{$to->format('Ymd')}_e-invoices.zip"));

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0750, true);
        }

        $documents = $archive->write($organization, $from, $to, $path, withPdf: ! $this->option('without-pdf'));

        $audit->logFor((string) $organization->id, ArchiveDownload::AUDIT_ACTION, $organization, [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'documents' => $documents,
            'sha256' => hash_file('sha256', $path),
        ]);

        $this->info("{$documents} documents written to {$path}");

        return self::SUCCESS;
    }
}
