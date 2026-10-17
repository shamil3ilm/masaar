<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Engine\Contracts;

use App\Domains\Compliance\Engine\DTOs\Panel;
use Carbon\CarbonImmutable;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A period's sales as the country's tax return groups them, and the invoices
 * behind them, for the portal's Reports page and its two CSVs.
 *
 * Each country's return is its own - its boxes or categories, its currency,
 * which documents count - so the page, the summary and the files all come
 * from the country. The period is the shared page's.
 */
interface TaxReports
{
    /**
     * The Reports page: a full portal screen of the country's, given the
     * period, its presets and the summary it reads here.
     */
    public function page(string $organizationId, CarbonImmutable $from, CarbonImmutable $to): Panel;

    /** The period's invoices, one row each. */
    public function invoicesCsv(string $organizationId, CarbonImmutable $from, CarbonImmutable $to, string $fileName): StreamedResponse;

    /** The period's summary, as the page shows it. */
    public function summaryCsv(string $organizationId, CarbonImmutable $from, CarbonImmutable $to, string $fileName): StreamedResponse;
}
