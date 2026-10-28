<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\UnitedArabEmirates\Presentation;

use App\Domains\Compliance\Countries\UnitedArabEmirates\Rules\SellerRules;
use App\Domains\Compliance\Countries\UnitedArabEmirates\Support\EinvoicingStart;
use App\Domains\Compliance\Countries\UnitedArabEmirates\Support\ProviderAppointments;
use App\Domains\Compliance\Engine\Contracts\SetupSteps as SetupStepsPort;
use App\Domains\Invoice\Enums\InvoiceStatus;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;

/**
 * A UAE organization's own setup steps: its seller details and the Peppol
 * endpoint its service provider issued, the appointment of that provider,
 * and a first invoice sent as a UAE e-invoice. It registers nothing with
 * the authority itself: the service provider carries every document.
 */
class SetupSteps implements SetupStepsPort
{
    public function __construct(
        private readonly SellerRules $seller,
        private readonly ProviderAppointments $appointments,
        private readonly EinvoicingStart $start,
    ) {}

    public function company(Organization $organization): array
    {
        return [
            'key' => 'uae_company',
            'done' => $this->seller->complete($organization),
            'required' => true,
            'route' => 'portal.company.edit',
            'title' => __('Complete your UAE seller details'),
            'detail' => __('Your TRN or TIN, address and emirate, legal registration, and the Peppol endpoint your e-invoicing service provider issued.'),
            'member' => false,
        ];
    }

    /**
     * Appointing its accredited service provider: one, for sending and
     * receiving, by the last date its revenue band sets, through EmaraTax
     * (UAE Electronic Invoicing Guidelines v1.1, p14, p19, p38-39). Done once
     * recorded as appointed.
     */
    public function onboarding(Organization $organization): array
    {
        $deadline = $this->appointments->deadline((string) $organization->id);

        return [$this->stage($organization), [
            'key' => 'uae_service_provider',
            'done' => $this->appointments->appointed((string) $organization->id),
            'required' => true,
            'route' => 'portal.company.edit',
            'title' => __('Appoint your e-invoicing service provider'),
            'detail' => $deadline !== null
                ? __('One accredited service provider sends and receives your e-invoices. Appoint it through EmaraTax by :date, then record it on your company page.', ['date' => $deadline->toDateString()])
                : __('One accredited service provider sends and receives your e-invoices. Appoint it through EmaraTax, then record it on your company page.'),
            'member' => false,
        ]];
    }

    /**
     * Its stage: before e-invoicing - invoices kept, never sent - or via its
     * provider from its start date (UAE Electronic Invoicing Guidelines v1.1,
     * 6.1). Done once a date is set; not required, since a business not yet
     * in scope rightly has none. The console links to where an admin sets it.
     */
    private function stage(Organization $organization): array
    {
        $date = $this->start->date($organization);
        $inScope = $this->start->inScope($organization);

        return [
            'key' => 'uae_einvoicing_stage',
            'done' => $date !== null,
            'required' => false,
            'route' => 'portal.company.edit',
            'admin_route' => 'admin.organizations.einvoicing-start',
            'title' => $inScope
                ? __('E-invoicing via provider from :date', ['date' => $date?->toDateString()])
                : __('Before e-invoicing'),
            'detail' => match (true) {
                $inScope => __('Every invoice is issued and sent through your service provider.'),
                $date !== null => __('From :date, every invoice you issue is sent through your service provider.', ['date' => $date->toDateString()]),
                default => __('Your invoices are tax invoices kept here and not sent. Set your e-invoicing start date on your company page.'),
            },
            'member' => false,
        ];
    }

    /** Done once any invoice is past draft: a UAE invoice is issued without a hash. */
    public function firstInvoice(Organization $organization): array
    {
        return [
            'key' => 'uae_invoice',
            'done' => Invoice::acrossTenants()->where('org_id', $organization->id)->where('status', '!=', InvoiceStatus::Draft->value)->exists(),
            'required' => true,
            'route' => 'portal.invoices.create',
            'title' => __('Issue your first invoice'),
            'detail' => __('Once it is issued, it is sent to the buyer as a UAE e-invoice.'),
            'member' => true,
        ];
    }
}
