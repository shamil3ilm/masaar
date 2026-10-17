<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Presentation;

use App\Domains\Compliance\Countries\SaudiArabia\Rules\SellerRules;
use App\Domains\Compliance\Engine\Contracts\SetupSteps as SetupStepsPort;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;

/**
 * A Saudi organization's own setup steps: its VAT number and national
 * address, connecting to ZATCA, and a first invoice that is signed and filed.
 *
 * A company that signed up landed on a dashboard of zeros and a red banner
 * saying it had no certificate - true, and no help, because nothing said
 * that connecting to ZATCA was the next step or where to do it.
 */
class SetupSteps implements SetupStepsPort
{
    public function __construct(
        private readonly SellerRules $seller,
    ) {}

    public function company(Organization $organization): array
    {
        return [
            'key' => 'company',
            'done' => $this->seller->complete($organization),
            'required' => true,
            'route' => 'portal.company.edit',
            'title' => __('Complete your company details'),
            'detail' => __('Your VAT number and national address, as they go on every invoice.'),
            'member' => false,
        ];
    }

    public function onboarding(Organization $organization): array
    {
        return [[
            'key' => 'zatca',
            'done' => $organization->zatca_onboarded,
            'required' => true,
            'route' => 'portal.zatca.show',
            'title' => __('Connect to ZATCA'),
            'detail' => __('With the one-time code from the Fatoora portal. Your invoices are signed with the certificate it issues.'),
            'member' => false,
        ]];
    }

    /** Done once an invoice has its hash: signed, and in the chain. */
    public function firstInvoice(Organization $organization): array
    {
        return [
            'key' => 'invoice',
            'done' => Invoice::withoutTenantScope(fn () => Invoice::query()->where('org_id', $organization->id)->whereNotNull('hash')->exists()),
            'required' => true,
            'route' => 'portal.invoices.create',
            'title' => __('Issue your first invoice'),
            'detail' => __('Once it is issued, it is signed and filed with ZATCA.'),
            'member' => true,
        ];
    }
}
