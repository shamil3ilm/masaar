<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Reports;

use App\Domains\Compliance\Countries\SaudiArabia\Models\ChainEntry;
use App\Domains\Compliance\Countries\SaudiArabia\Support\FatooraTime;
use App\Domains\Invoice\Enums\InvoiceStatus;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Services\InvoicePdf;
use App\Domains\Organization\Models\Organization;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Collection;
use RuntimeException;
use ZipArchive;

/**
 * A period's issued documents as one ZIP, for the taxpayer's own archive.
 *
 * "The system must allow Persons subject to the E-Invoicing Regulation to
 * export and save their invoices onto an external archival system", each
 * file named "VAT Registration (tax registration number) + Timestamp (date
 * and time at the point of invoice generation) + Invoice Reference Number"
 * (E-Invoicing Detailed Guidelines 5.5, p26), which the XML Implementation
 * Standard v1.2 section 14 spells out:
 *
 *     Seller VAT number (BT-31) "_" issue date (BT-2) without dashes "T"
 *     issue time (KSA-25) without colons "_" invoice number (BT-1) with
 *     every non-alphanumeric character replaced by "-" ".xml"
 *
 * e.g. 3xxxxxxxxx1xxx3_20210526T132400_2021-05-26-23555.xml. Inside:
 *
 *   signed/   each document as the device signed it (what was reported)
 *   cleared/  a standard document as ZATCA cleared it - the legal invoice
 *   pdf/      the PDF/A-3 the buyer is given, where it may be given
 *   manifest.csv  one row per document: number, UUID, ICV, hash, PIH,
 *                 status, ZATCA's answer, each file's SHA-256
 *   SHA256SUMS    every file's SHA-256, for `sha256sum -c`
 *
 * Read a page of documents at a time and written to a file on disk, closed
 * after each page, so a period of any size is not held in memory.
 */
final class DocumentArchive
{
    private const PAGE = 100;

    private const COLUMNS = [
        'file', 'invoice_number', 'uuid', 'icv', 'type', 'document_type', 'issue_date', 'issue_time',
        'hash', 'pih', 'status', 'zatca_response', 'signed_xml_sha256', 'cleared_xml_sha256', 'pdf_sha256',
    ];

    public function __construct(private readonly InvoicePdf $pdf) {}

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

        // A plain closure, not an arrow function: the page callback swaps the
        // archive it writes to, and an arrow function would hand it a copy.
        Invoice::withoutTenantScope(function () use ($organization, $from, $to, $path, $withPdf, $manifest, &$zip, &$sums, &$count): void {
            Invoice::query()
                ->where('org_id', $organization->id)
                ->where('status', '!=', InvoiceStatus::Draft->value)
                ->whereDate('issue_date', '>=', $from->toDateString())
                ->whereDate('issue_date', '<=', $to->toDateString())
                ->chunkById(self::PAGE, function (Collection $page) use ($organization, $path, $withPdf, $manifest, &$zip, &$sums, &$count): void {
                    $pih = ChainEntry::withoutTenantScope(fn () => ChainEntry::query()
                        ->where('org_id', $organization->id)
                        ->whereIn('invoice_id', $page->modelKeys())
                        ->pluck('previous_hash', 'invoice_id'));

                    foreach ($page as $invoice) {
                        $name = self::fileName($invoice, $organization);
                        $hashes = [
                            'signed' => $this->add($zip, "signed/{$name}.xml", $invoice->signed_xml, $sums),
                            'cleared' => $this->add($zip, "cleared/{$name}.xml", $invoice->cleared_xml, $sums),
                            'pdf' => $withPdf ? $this->add($zip, "pdf/{$name}.pdf", $this->pdfOf($invoice, $organization), $sums) : null,
                        ];

                        fputcsv($manifest, [
                            $name,
                            $invoice->invoice_number,
                            $invoice->id,
                            $invoice->icv,
                            $invoice->type?->value,
                            $invoice->document_type?->value,
                            $invoice->issue_date?->toDateString(),
                            self::issueTime($invoice),
                            $invoice->hash,
                            $pih[$invoice->id] ?? null,
                            $invoice->status->value,
                            $invoice->zatca_response === null ? null : json_encode($invoice->zatca_response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                            $hashes['signed'],
                            $hashes['cleared'],
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

    /**
     * The file name ZATCA's XML Implementation Standard (v1.2, section 14)
     * gives an electronic invoice, without its extension.
     */
    public static function fileName(Invoice $invoice, Organization $organization): string
    {
        $date = str_replace('-', '', (string) $invoice->issue_date?->toDateString());
        $time = str_replace(':', '', self::issueTime($invoice));
        $number = (string) preg_replace('/[^A-Za-z0-9]/', '-', (string) $invoice->invoice_number);

        return "{$organization->vat_number}_{$date}T{$time}_{$number}";
    }

    /** KSA-25 as the document carries it: the Kingdom's time of issue. */
    private static function issueTime(Invoice $invoice): string
    {
        return FatooraTime::toSaudiTime($invoice->issuedMoment())->format('H:i:s');
    }

    /** The PDF the buyer may be given, or none while it may not be (an uncleared standard invoice, a rejected one). */
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
