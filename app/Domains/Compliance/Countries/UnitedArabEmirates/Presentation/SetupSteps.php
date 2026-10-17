<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\UnitedArabEmirates\Presentation;

use App\Domains\Compliance\Countries\UnitedArabEmirates\Rules\SellerRules;
use App\Domains\Compliance\Engine\Contracts\SetupSteps as SetupStepsPort;
use App\Domains\Invoice\Enums\InvoiceStatus;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;

/**
 * A UAE organization's own setup steps: its seller details and the Peppol
 * endpoint its service provider issued, and a first invoice sent as a UAE
 * e-invoice. It registers nothing with the authority itself: the service
 * provider carries every document.
 */
class SetupSteps implements SetupStepsPort
{
    public function __construct(
        private readonly SellerRules $seller,
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

    public function onboarding(Organization $organization): array
    {
        return [];
    }

    /** Done once any invoice is past draft: a UAE invoice is issued without a hash. */
    public function firstInvoice(Organization $organization): array
    {
        return [
            'key' => 'uae_invoice',
            'done' => Invoice::withoutTenantScope(fn () => Invoice::query()->where('org_id', $organization->id)->where('status', '!=', InvoiceStatus::Draft->value)->exists()),
            'required' => true,
            'route' => 'portal.invoices.create',
            'title' => __('Issue your first invoice'),
            'detail' => __('Once it is issued, it is sent to the buyer as a UAE e-invoice.'),
            'member' => true,
        ];
    }
}
