<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Reports;

use App\Domains\Compliance\Countries\SaudiArabia\Rules\FatooraConfig;
use App\Domains\Compliance\Engine\Contracts\TaxReports as TaxReportsPort;
use App\Domains\Compliance\Engine\DTOs\Panel;
use App\Domains\Invoice\Services\InvoiceCsv;
use App\Domains\Invoice\Services\VatSummary;
use Carbon\CarbonImmutable;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The Saudi VAT return's boxes, in riyals, for the period: the documents
 * ZATCA did not refuse, by tax point, with credit notes for an earlier
 * period under Adjustments (VatSummary). And a day's close per device and
 * per branch (DailyClose), since ZATCA counts documents per device. The
 * page also splits the figures by where each document stands with ZATCA
 * (FilingStatus), so they can be tied to the filing record.
 */
class TaxReports implements TaxReportsPort
{
    /** The close's columns, the CSV's header, in the order a row has them. */
    private const CLOSE_COLUMNS = [
        'Section', 'Name', 'Documents', 'Invoices', 'Credit notes', 'Debit notes', 'Refused by ZATCA',
        'First number', 'First counter', 'Last number', 'Last counter', 'Counters without gaps',
        'Sales net (SAR)', 'Sales VAT (SAR)', 'Sales total (SAR)',
        'Credit notes net (SAR)', 'Credit notes VAT (SAR)', 'Credit notes total (SAR)', 'Net total (SAR)',
        'Left out: no exchange rate',
    ];

    public function __construct(
        private readonly VatSummary $summary,
        private readonly InvoiceCsv $csv,
        private readonly DailyClose $close,
        private readonly FilingStatus $filing,
    ) {}

    public function page(string $organizationId, CarbonImmutable $from, CarbonImmutable $to): Panel
    {
        return new Panel('countries.saudi-arabia.reports', [
            'summary' => $this->summary->for($organizationId, $from, $to),
            'filing' => $this->filing->forSummary($organizationId, $from, $to),
        ]);
    }

    /** The return's net amounts: the boxes with their adjustments. */
    public function totals(string $organizationId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $summary = $this->summary->for($organizationId, $from, $to);

        return [
            'documents' => $summary['documents'],
            'base' => $summary['net_base'],
            'vat' => $summary['net_vat'],
            'currency' => FatooraConfig::CURRENCY_SAR,
        ];
    }

    public function invoicesCsv(string $organizationId, CarbonImmutable $from, CarbonImmutable $to, string $fileName): StreamedResponse
    {
        return $this->csv->invoices($organizationId, $from, $to, $fileName);
    }

    public function summaryCsv(string $organizationId, CarbonImmutable $from, CarbonImmutable $to, string $fileName): StreamedResponse
    {
        return $this->csv->summary($organizationId, $from, $to, $fileName);
    }

    public function dailyClose(string $organizationId, CarbonImmutable $day): Panel
    {
        return new Panel('countries.saudi-arabia.daily-close', [
            'close' => $this->close->for($organizationId, $day),
        ]);
    }

    /**
     * One row per device and per branch, then one per VAT rate of each. The
     * labels stay English, as the other files' do.
     */
    public function dailyCloseCsv(string $organizationId, CarbonImmutable $day, string $fileName): StreamedResponse
    {
        $close = $this->close->for($organizationId, $day);
        $sections = ['Device' => $close['devices'], 'Branch' => $close['branches']];
        $money = fn (float $amount): string => number_format($amount + 0.0, 2, '.', '');

        $rows = [['Day', $close['day']], self::CLOSE_COLUMNS];

        foreach ($sections as $section => $groups) {
            foreach ($groups as $group) {
                $rows[] = [
                    $section, $group['head_office'] ? 'Head office' : (string) $group['name'],
                    (string) $group['documents'], (string) $group['invoices'], (string) $group['credit_notes'],
                    (string) $group['debit_notes'], (string) $group['rejected'],
                    (string) ($group['first']['number'] ?? ''), (string) ($group['first']['counter'] ?? ''),
                    (string) ($group['last']['number'] ?? ''), (string) ($group['last']['counter'] ?? ''),
                    $group['contiguous'] ? 'yes' : 'no',
                    $money($group['sales']['net']), $money($group['sales']['vat']), $money($group['sales']['total']),
                    $money($group['returns']['net']), $money($group['returns']['vat']), $money($group['returns']['total']),
                    $money($group['net']),
                    implode(' ', $group['missing_rate']),
                ];
            }
        }

        $rows[] = ['Section', 'Name', 'VAT rate (%)', 'Net (SAR)', 'VAT (SAR)'];

        foreach ($sections as $section => $groups) {
            foreach ($groups as $group) {
                foreach ($group['rates'] as $rate) {
                    $rows[] = [$section, $group['head_office'] ? 'Head office' : (string) $group['name'], $rate['rate'], $money($rate['net']), $money($rate['vat'])];
                }
            }
        }

        return $this->csv->table($fileName, $rows);
    }
}
