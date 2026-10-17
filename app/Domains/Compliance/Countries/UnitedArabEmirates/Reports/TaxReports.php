<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\UnitedArabEmirates\Reports;

use App\Domains\Compliance\Engine\Contracts\TaxReports as TaxReportsPort;
use App\Domains\Compliance\Engine\DTOs\Panel;
use App\Domains\Invoice\Services\InvoiceCsv;
use Carbon\CarbonImmutable;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A UAE organization's sales for the period by UAE tax category, in
 * dirhams (UaeVatSummary): a working summary for its return, which the
 * organization files itself.
 */
class TaxReports implements TaxReportsPort
{
    public function __construct(
        private readonly UaeVatSummary $summary,
        private readonly InvoiceCsv $csv,
    ) {}

    public function page(string $organizationId, CarbonImmutable $from, CarbonImmutable $to): Panel
    {
        return new Panel('countries.united-arab-emirates.reports', [
            'summary' => $this->summary->for($organizationId, $from, $to),
        ]);
    }

    public function invoicesCsv(string $organizationId, CarbonImmutable $from, CarbonImmutable $to, string $fileName): StreamedResponse
    {
        return $this->csv->uaeInvoices($organizationId, $from, $to, $this->summary->currency(), $fileName);
    }

    public function summaryCsv(string $organizationId, CarbonImmutable $from, CarbonImmutable $to, string $fileName): StreamedResponse
    {
        return $this->csv->uaeSummary($this->summary->for($organizationId, $from, $to), $from, $to, $fileName);
    }

    /** None: a UAE document carries no device counter to close. */
    public function dailyClose(string $organizationId, CarbonImmutable $day): ?Panel
    {
        return null;
    }

    public function dailyCloseCsv(string $organizationId, CarbonImmutable $day, string $fileName): ?StreamedResponse
    {
        return null;
    }
}
