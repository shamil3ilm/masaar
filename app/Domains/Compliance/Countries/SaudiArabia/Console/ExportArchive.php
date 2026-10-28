<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Console;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Compliance\Countries\SaudiArabia\Reports\DocumentArchive;
use App\Domains\Organization\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * An organization's issued e-invoices for a period as one ZIP on disk
 * (DocumentArchive): signed and cleared XML named as ZATCA's XML
 * Implementation Standard (v1.2, section 14) names them, the PDFs, a
 * manifest and SHA256SUMS. No limit on the period: it is written a page at
 * a time.
 */
class ExportArchive extends Command
{
    protected $signature = 'fatoora:export-archive
                            {organization : The organization\'s id}
                            {--from= : First issue date, YYYY-MM-DD}
                            {--to= : Last issue date, YYYY-MM-DD}
                            {--path= : Where to write the ZIP}
                            {--without-pdf : Leave the PDFs out}';

    protected $description = 'Write a period\'s issued e-invoices, a manifest and checksums to a ZIP';

    public function handle(DocumentArchive $archive, AuditService $audit): int
    {
        $organization = Organization::query()->find((string) $this->argument('organization'));

        if ($organization === null) {
            $this->error('No such organization.');

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

        $path = (string) ($this->option('path') ?: storage_path("app/archives/{$organization->vat_number}_{$from->format('Ymd')}-{$to->format('Ymd')}_e-invoices.zip"));

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0750, true);
        }

        $documents = $archive->write($organization, $from, $to, $path, withPdf: ! $this->option('without-pdf'));

        $audit->logFor((string) $organization->id, 'zatca.archive.exported', $organization, [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'documents' => $documents,
            'sha256' => hash_file('sha256', $path),
        ]);

        $this->info("{$documents} documents written to {$path}");

        return self::SUCCESS;
    }
}
