<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Reports;

use App\Domains\Compliance\Engine\Contracts\TaxReports as TaxReportsPort;
use App\Domains\Compliance\Engine\DTOs\Panel;
use App\Domains\Invoice\Services\InvoiceCsv;
use App\Domains\Invoice\Services\VatSummary;
use Carbon\CarbonImmutable;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The Saudi VAT return's boxes, in riyals, for the period: the documents
 * ZATCA did not refuse, by tax point, with credit notes for an earlier
 * period under Adjustments (VatSummary).
 */
class TaxReports implements TaxReportsPort
{
    public function __construct(
        private readonly VatSummary $summary,
        private readonly InvoiceCsv $csv,
    ) {}

    public function page(string $organizationId, CarbonImmutable $from, CarbonImmutable $to): Panel
    {
        return new Panel('countries.saudi-arabia.reports', [
            'summary' => $this->summary->for($organizationId, $from, $to),
        ]);
    }

    public function invoicesCsv(string $organizationId, CarbonImmutable $from, CarbonImmutable $to, string $fileName): StreamedResponse
    {
        return $this->csv->invoices($organizationId, $from, $to, $fileName);
    }

    public function summaryCsv(string $organizationId, CarbonImmutable $from, CarbonImmutable $to, string $fileName): StreamedResponse
    {
        return $this->csv->summary($organizationId, $from, $to, $fileName);
    }
}
