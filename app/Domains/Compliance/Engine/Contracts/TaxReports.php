<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Engine\Contracts;

use App\Domains\Compliance\Engine\DTOs\Panel;
use Carbon\CarbonImmutable;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A period's sales as the country's tax return groups them, and the invoices
 * behind them, for the portal's Reports page and its two CSVs - and, where a
 * country counts its documents per device, a day's close by device.
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

    /**
     * One day's close (a Z-report): what each device and each branch issued
     * that day, as a full portal screen of the country's. Null where the
     * country keeps no counter per device, and there is no such page.
     */
    public function dailyClose(string $organizationId, CarbonImmutable $day): ?Panel;

    /** The day's close as the page shows it, or null where there is none. */
    public function dailyCloseCsv(string $organizationId, CarbonImmutable $day, string $fileName): ?StreamedResponse;
}
