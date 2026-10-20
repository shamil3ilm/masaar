<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\UnitedArabEmirates\Reports;

use App\Domains\Compliance\Countries\UnitedArabEmirates\Support\EinvoicingStart;
use App\Domains\Compliance\Countries\UnitedArabEmirates\Support\EinvoicingStartReminders;
use App\Domains\Compliance\Engine\Contracts\Dashboard as DashboardPort;
use App\Domains\Compliance\Engine\DTOs\Panel;
use App\Domains\Invoice\Services\InvoiceReport;
use App\Domains\Organization\Models\Organization;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * A UAE organization's dashboard: its invoices, and where their e-invoices
 * are. It files nothing with the authority itself and signs nothing, so its
 * figures are its Peppol documents, counted the same way on the portal and
 * the API (UaeDocuments::counts).
 */
class Dashboard implements DashboardPort
{
    public function __construct(
        private readonly InvoiceReport $invoices,
        private readonly UaeDocuments $documents,
        private readonly EinvoicingStart $start,
        private readonly EinvoicingStartReminders $reminders,
    ) {}

    public function heading(): string
    {
        return __('Your UAE e-invoicing overview');
    }

    public function portal(Organization $organization): Panel
    {
        return new Panel('countries.united-arab-emirates.dashboard', [
            'stats' => [
                'invoices_today' => $this->invoices->countSince(now()->startOfDay()),
                'invoices_month' => $this->invoices->countSince(now()->startOfMonth()),
            ],
            'einvoices' => $this->documents->counts((string) $organization->id),
            'recentDocuments' => $this->documents->recentSent((string) $organization->id, 10),
            // Its stage - before e-invoicing, or via its provider from a
            // date - and, within 90 days of that date with no provider
            // appointed, the reminder (EinvoicingStartReminders).
            'einvoicingStart' => $this->start->date($organization),
            'inScope' => $this->start->inScope($organization),
            'startReminder' => $this->reminders->due($organization),
        ]);
    }

    public function overview(string $organizationId): array
    {
        return ['e_invoices' => $this->documents->counts($organizationId)];
    }

    /**
     * Its e-invoices, and a verdict from them: a warning while any was
     * refused or given up on, since each needs someone to act.
     */
    public function health(string $organizationId): array
    {
        $counts = $this->documents->counts($organizationId);

        return [
            'checks' => ['e_invoices' => $counts],
            'status' => $counts['failed'] + $counts['rejected'] > 0 ? 'warning' : 'healthy',
        ];
    }

    public function filings(string $organizationId): array
    {
        return $this->documents->counts($organizationId);
    }

    /** None: a UAE e-invoice is sent by the service provider, not filed by the platform. */
    public function dailyFilings(CarbonInterface $since): array
    {
        return [];
    }

    /** None, for the same reason: the documents' own statuses are the activity. */
    public function activity(int $limit): Collection
    {
        return new Collection;
    }
}
