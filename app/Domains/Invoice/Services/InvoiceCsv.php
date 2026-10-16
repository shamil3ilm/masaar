<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Services;

use App\Domains\Invoice\Models\Invoice;
use Carbon\CarbonImmutable;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Invoices and the VAT summary as CSV, for an accountant's spreadsheet.
 *
 * Streamed, so a year of invoices is not held in memory. A byte-order mark
 * first, or Excel reads Arabic names as mojibake. A cell that starts like a
 * formula is prefixed with an apostrophe: a buyer named "=HYPERLINK(...)"
 * would otherwise run when the file is opened.
 */
class InvoiceCsv
{
    public function __construct(
        private readonly VatSummary $summary,
    ) {}

    public function invoices(string $organizationId, CarbonImmutable $from, CarbonImmutable $to, string $fileName): StreamedResponse
    {
        return $this->stream($fileName, function ($out) use ($organizationId, $from, $to): void {
            $this->row($out, ['Invoice number', 'Type', 'Document', 'Issue date', 'Buyer', 'Buyer VAT number', 'Currency', 'Taxable amount', 'VAT', 'Total', 'Status', 'Counter']);

            foreach ($this->summary->documents($organizationId, $from, $to) as $invoice) {
                $this->row($out, $this->fields($invoice));
            }
        });
    }

    public function summary(string $organizationId, CarbonImmutable $from, CarbonImmutable $to, string $fileName): StreamedResponse
    {
        $summary = $this->summary->for($organizationId, $from, $to);

        return $this->stream($fileName, function ($out) use ($summary, $from, $to): void {
            $this->row($out, ['Period', $from->toDateString().' to '.$to->toDateString()]);
            $this->row($out, ['Box', 'Sales', 'Amount (SAR)', 'VAT (SAR)']);

            foreach ($summary['boxes'] as $box => $values) {
                $this->row($out, [(string) $box, $values['label'], $this->money($values['base']), $this->money($values['vat'])]);
            }

            $this->row($out, ['', 'Total sales', $this->money($summary['total_base']), $this->money($summary['total_vat'])]);
            $this->row($out, ['', 'Out of scope (not on the return)', $this->money($summary['out_of_scope']), '']);
        });
    }

    /**
     * @return list<string>
     */
    private function fields(Invoice $invoice): array
    {
        $value = fn ($enum): string => $enum instanceof \BackedEnum ? (string) $enum->value : (string) $enum;

        return [
            (string) $invoice->invoice_number,
            $value($invoice->type),
            $value($invoice->document_type),
            (string) $invoice->issue_date?->toDateString(),
            $invoice->documentBuyerName(),
            (string) $invoice->buyer_vat_number,
            (string) $invoice->currency,
            $this->money((float) $invoice->subtotal - (float) $invoice->discount_amount),
            $this->money((float) $invoice->tax_amount),
            $this->money((float) $invoice->total),
            $value($invoice->status),
            (string) $invoice->icv,
        ];
    }

    /**
     * @param  callable(resource): void  $write
     */
    private function stream(string $fileName, callable $write): StreamedResponse
    {
        return response()->streamDownload(function () use ($write): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            $write($out);
            fclose($out);
        }, $fileName, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * @param  resource  $out
     * @param  list<string>  $cells
     */
    private function row($out, array $cells): void
    {
        fputcsv($out, array_map(fn (string $cell): string => preg_match('/^[=+\-@\t\r]/', $cell) === 1 && ! is_numeric($cell) ? "'".$cell : $cell, $cells), escape: '');
    }

    private function money(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }
}
