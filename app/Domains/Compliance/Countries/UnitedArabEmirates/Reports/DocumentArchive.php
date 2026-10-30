<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\UnitedArabEmirates\Reports;

use App\Domains\Compliance\Countries\UnitedArabEmirates\Rules\PintAe;
use App\Domains\Compliance\Networks\Peppol\Models\PeppolDocument;
use App\Domains\Invoice\Enums\InvoiceStatus;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Services\InvoicePdf;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Services\TenantResolver;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Collection;
use RuntimeException;
use ZipArchive;

/**
 * A period's issued UAE documents as one ZIP, for the taxpayer's own records:
 * the e-invoices are kept for the retention period the tax law sets, and the
 * document that travelled is the record (PeppolDocument). Inside:
 *
 *   xml/      each PINT AE document exactly as it was stored to be sent -
 *             also one kept and never sent, issued before the organization's
 *             e-invoicing start date
 *   pdf/      the PDF/A-3 the buyer is given, where it may be given
 *   manifest.csv  one row per document: number, the document's UUID, its
 *                 delivery status and message level response, the report to
 *                 the authority and the authority's reference, the
 *                 provider's references, each file's SHA-256
 *   SHA256SUMS    every file's SHA-256, for `sha256sum -c`
 *
 * No file-name convention is published for UAE e-invoices, so each is named
 * seller TRN "_" issue date (YYYYMMDD) "_" invoice number with every
 * non-alphanumeric character replaced by "-".
 *
 * Read a page of documents at a time and written to a file on disk, closed
 * after each page, so a period of any size is not held in memory.
 */
final class DocumentArchive
{
    private const PAGE = 100;

    private const COLUMNS = [
        'file', 'invoice_number', 'document_uuid', 'type', 'document_type', 'issue_date', 'status',
        'delivery_status', 'mls_code', 'reporting_status', 'authority_reference', 'provider_reference',
        'asp_message_id', 'issued_late', 'xml_sha256', 'pdf_sha256',
    ];

    public function __construct(
        private readonly InvoicePdf $pdf,
        private readonly TenantResolver $tenants,
        private readonly PintAe $profile,
    ) {}

    /**
     * Writes the archive to $path.
     *
     * @return int the number of documents in it
     */
    public function write(Organization $organization, CarbonInterface $from, CarbonInterface $to, string $path, bool $withPdf = true): int
    {
        $zip = $this->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $manifest = fopen('php://temp', 'w+') ?: throw new RuntimeException('Cannot write the archive manifest.');
        fputcsv($manifest, self::COLUMNS, escape: '');
        $sums = [];
        $count = 0;
        $trn = $this->trn($organization);

        // A plain closure, not an arrow function: the page callback swaps the
        // archive it writes to, and an arrow function would hand it a copy.
        //
        // As the organization, whoever asks - the portal, a platform admin, a
        // command: every query inside, the PDFs' included, reads its rows and
        // only its rows.
        $this->tenants->runAs((string) $organization->id, function () use ($organization, $from, $to, $path, $withPdf, $manifest, $trn, &$zip, &$sums, &$count): void {
            Invoice::query()
                ->where('org_id', $organization->id)
                ->where('status', '!=', InvoiceStatus::Draft->value)
                ->whereDate('issue_date', '>=', $from->toDateString())
                ->whereDate('issue_date', '<=', $to->toDateString())
                ->chunkById(self::PAGE, function (Collection $page) use ($organization, $path, $withPdf, $manifest, $trn, &$zip, &$sums, &$count): void {
                    $documents = PeppolDocument::query()
                        ->where('org_id', $organization->id)
                        ->where('direction', PeppolDocument::DIRECTION_OUT)
                        ->whereIn('invoice_id', $page->modelKeys())
                        ->get()
                        ->keyBy('invoice_id');

                    foreach ($page as $invoice) {
                        $name = self::name($invoice, $trn);
                        $document = $documents->get($invoice->id);
                        $hashes = [
                            'xml' => $this->add($zip, "xml/{$name}.xml", $document?->xml, $sums),
                            'pdf' => $withPdf ? $this->add($zip, "pdf/{$name}.pdf", $this->pdfOf($invoice, $organization), $sums) : null,
                        ];

                        fputcsv($manifest, [
                            $name,
                            $invoice->invoice_number,
                            $document?->document_uuid,
                            $invoice->type?->value,
                            $invoice->document_type?->value,
                            $invoice->issue_date?->toDateString(),
                            $invoice->status->value,
                            $document?->status,
                            $document?->mls_code,
                            $document?->reporting_status,
                            $document?->authority_reference,
                            $document?->provider_reference,
                            $document?->asp_message_id,
                            $document === null ? null : ($document->issued_late ? '1' : '0'),
                            $hashes['xml'],
                            $hashes['pdf'],
                        ], escape: '');
                        $count++;
                    }

                    // Written out a page at a time: ZipArchive keeps what it is
                    // given until it is closed.
                    $zip->close();
                    $zip = $this->open($path, 0);
                }, 'id');
        });

        rewind($manifest);
        $this->add($zip, 'manifest.csv', (string) stream_get_contents($manifest), $sums);
        fclose($manifest);

        $zip->addFromString('SHA256SUMS', implode('', array_map(fn (string $file, string $sum): string => "{$sum}  {$file}\n", array_keys($sums), $sums)));
        $zip->close();

        return $count;
    }

    /** A document's name in the archive, without its extension. */
    public function fileName(Invoice $invoice, Organization $organization): string
    {
        return self::name($invoice, $this->trn($organization));
    }

    /**
     * The seller's TRN: its active UAE profile's, else the organization's
     * VAT number. Read as the organization, whoever asks.
     */
    public function trn(Organization $organization): string
    {
        return (string) $this->tenants->runAs((string) $organization->id, fn (): ?string => $organization->complianceProfileFor($this->profile->country())?->setting('trn') ?? $organization->vat_number);
    }

    private static function name(Invoice $invoice, string $trn): string
    {
        $date = str_replace('-', '', (string) $invoice->issue_date?->toDateString());
        $number = (string) preg_replace('/[^A-Za-z0-9]/', '-', (string) $invoice->invoice_number);

        return "{$trn}_{$date}_{$number}";
    }

    /** The PDF the buyer may be given, or none while it may not be. */
    private function pdfOf(Invoice $invoice, Organization $organization): ?string
    {
        try {
            return $this->pdf->render($invoice, $organization);
        } catch (DomainException) {
            return null;
        }
    }

    /**
     * @param  array<string, string>  $sums
     */
    private function add(ZipArchive $zip, string $file, ?string $content, array &$sums): ?string
    {
        if ($content === null || $content === '') {
            return null;
        }

        $zip->addFromString($file, $content);

        return $sums[$file] = hash('sha256', $content);
    }

    private function open(string $path, int $flags): ZipArchive
    {
        $zip = new ZipArchive;

        if ($zip->open($path, $flags) !== true) {
            throw new RuntimeException("Cannot write the archive at {$path}.");
        }

        return $zip;
    }
}
