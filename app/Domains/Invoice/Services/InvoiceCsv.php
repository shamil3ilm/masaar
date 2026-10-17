<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Services;

use App\Domains\Invoice\Enums\DocumentType;
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
            $this->row($out, ['Invoice number', 'Type', 'Document', 'Issue date', 'Buyer', 'Buyer VAT number', 'Currency', 'Taxable amount', 'VAT', 'Total', 'Taxable amount (SAR)', 'VAT (SAR)', 'Total (SAR)', 'Status', 'Counter']);

            foreach ($this->summary->documents($organizationId, $from, $to) as $invoice) {
                $this->row($out, [...$this->fields($invoice, 'SAR'), (string) $invoice->icv]);
            }
        });
    }

    /**
     * A UAE organization's invoices: every one issued in the period, in its
     * own currency and in dirhams. No counter column - a UAE invoice has none.
     */
    public function uaeInvoices(string $organizationId, CarbonImmutable $from, CarbonImmutable $to, string $currency, string $fileName): StreamedResponse
    {
        return $this->stream($fileName, function ($out) use ($organizationId, $from, $to, $currency): void {
            $this->row($out, ['Invoice number', 'Type', 'Document', 'Issue date', 'Buyer', 'Buyer TRN', 'Currency', 'Taxable amount', 'VAT', 'Total', "Taxable amount ($currency)", "VAT ($currency)", "Total ($currency)", 'Status']);

            foreach ($this->summary->issued($organizationId, $from, $to) as $invoice) {
                $this->row($out, $this->fields($invoice, $currency));
            }
        });
    }

    /**
     * UaeVatSummary's figures: a working summary by UAE tax category, which
     * the file says it is, so it is not taken for the return.
     *
     * @param  array{currency: string, categories: array<string, array{label: string, base: float, vat: float}>, total_base: float, total_vat: float, missing_rate: list<string>, unreadable?: list<string>}  $summary
     */
    public function uaeSummary(array $summary, CarbonImmutable $from, CarbonImmutable $to, string $fileName): StreamedResponse
    {
        $currency = $summary['currency'];

        return $this->stream($fileName, function ($out) use ($summary, $from, $to, $currency): void {
            $this->row($out, ['Period', $from->toDateString().' to '.$to->toDateString()]);
            $this->row($out, ['Note', 'Working summary of sales by tax category, not the FTA VAT return form']);
            $this->row($out, ['Code', 'Tax category', "Amount ($currency)", "VAT ($currency)"]);

            foreach ($summary['categories'] as $code => $values) {
                $this->row($out, [(string) $code, $values['label'], $this->money($values['base']), $this->money($values['vat'])]);
            }

            $this->row($out, ['', 'Total', $this->money($summary['total_base']), $this->money($summary['total_vat'])]);

            if ($summary['missing_rate'] !== []) {
                $this->row($out, ['', 'Left out: no exchange rate', (string) count($summary['missing_rate']), implode(' ', $summary['missing_rate'])]);
            }

            if (($summary['unreadable'] ?? []) !== []) {
                $this->row($out, ['', 'Left out: tax category not used in the UAE', (string) count($summary['unreadable']), implode(' ', $summary['unreadable'])]);
            }
        });
    }

    public function summary(string $organizationId, CarbonImmutable $from, CarbonImmutable $to, string $fileName): StreamedResponse
    {
        $summary = $this->summary->for($organizationId, $from, $to);

        return $this->stream($fileName, function ($out) use ($summary, $from, $to): void {
            $this->row($out, ['Period', $from->toDateString().' to '.$to->toDateString()]);
            $this->row($out, ['Box', 'Sales', 'Amount (SAR)', 'Adjustments (SAR)', 'VAT (SAR)', 'VAT adjustments (SAR)']);

            foreach ($summary['boxes'] as $box => $values) {
                $this->row($out, [(string) $box, $values['label'], $this->money($values['base']), $this->money($values['adjustment_base']), $this->money($values['vat']), $this->money($values['adjustment_vat'])]);
            }

            $this->row($out, ['', 'Total sales', $this->money($summary['total_base']), $this->money($summary['total_adjustment_base']), $this->money($summary['total_vat']), $this->money($summary['total_adjustment_vat'])]);
            $this->row($out, ['', 'Net of adjustments', $this->money($summary['net_base']), '', $this->money($summary['net_vat']), '']);
            $this->row($out, ['', 'Out of scope (not on the return)', $this->money($summary['out_of_scope']), '', '', '']);

            if ($summary['missing_rate'] !== []) {
                $this->row($out, ['', 'Left out: no exchange rate', (string) count($summary['missing_rate']), implode(' ', $summary['missing_rate']), '', '']);
            }
        });
    }

    /**
     * Rows a report has already laid out, written the way every file here
     * is: a byte-order mark first, and a cell that starts like a formula
     * defused.
     *
     * @param  iterable<list<string>>  $rows
     */
    public function table(string $fileName, iterable $rows): StreamedResponse
    {
        return $this->stream($fileName, function ($out) use ($rows): void {
            foreach ($rows as $row) {
                $this->row($out, $row);
            }
        });
    }

    /**
     * A document's row, its amounts also in the tax currency.
     *
     * @return list<string>
     */
    private function fields(Invoice $invoice, string $taxCurrency): array
    {
        $value = fn ($enum): string => $enum instanceof \BackedEnum ? (string) $enum->value : (string) $enum;

        // A credit note is taken off, as in the summary, so the columns add
        // up to it. The tax-currency (riyal, dirham) columns are blank
        // without a rate: the summary leaves that document out rather than
        // guess.
        $sign = $invoice->document_type === DocumentType::CreditNote ? -1 : 1;
        $amounts = [
            $sign * ((float) $invoice->subtotal - (float) $invoice->discount_amount),
            $sign * (float) $invoice->tax_amount,
            $sign * (float) $invoice->total,
        ];
        $rate = $this->summary->rate($invoice, $taxCurrency);
        $inTaxCurrency = array_map(fn (float $amount): string => $rate === null ? '' : $this->money($amount * $rate), $amounts);

        return [
            (string) $invoice->invoice_number,
            $value($invoice->type),
            $value($invoice->document_type),
            (string) $invoice->issue_date?->toDateString(),
            $invoice->documentBuyerName(),
            (string) $invoice->buyer_vat_number,
            (string) $invoice->currency,
            ...array_map($this->money(...), $amounts),
            ...$inTaxCurrency,
            $value($invoice->status),
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
        // + 0.0 turns a negated zero into a plain one, not "-0.00".
        return number_format(round($amount, 2) + 0.0, 2, '.', '');
    }
}
