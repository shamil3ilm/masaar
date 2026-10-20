<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Presentation;

use App\Domains\Compliance\Countries\SaudiArabia\Rules\FatooraEnvironment;
use App\Domains\Compliance\Countries\SaudiArabia\Rules\SellerRules;
use App\Domains\Compliance\Engine\Contracts\SetupSteps as SetupStepsPort;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Services\Tills;

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
        private readonly Tills $tills,
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

    /**
     * Head office's connection, then each till still to be onboarded with
     * its own OTP: every EGS unit is onboarded on its own (E-Invoicing
     * Detailed Guidelines p10, p39). A till's step is optional - head office
     * issues for a branch whose till is not connected - and is gone once the
     * till is ready or retired.
     */
    public function onboarding(Organization $organization): array
    {
        $steps = [[
            'key' => 'zatca',
            'done' => $organization->zatca_onboarded,
            'required' => true,
            'route' => 'portal.zatca.show',
            // Which environment (FatooraEnvironment), set on the console.
            'admin_route' => 'admin.organizations.zatca-environment',
            'title' => __('Connect to ZATCA (:environment)', ['environment' => FatooraEnvironment::label(FatooraEnvironment::of($organization))]),
            'detail' => __('With the one-time code from the Fatoora portal. Your invoices are signed with the certificate it issues.'),
            'member' => false,
        ]];

        foreach ($this->tills->all((string) $organization->id) as $unit) {
            if ($unit->isHeadOffice() || ! $unit->issues() || $unit->isReady()) {
                continue;
            }

            $steps[] = [
                'key' => 'zatca-till-'.$unit->id,
                'done' => false,
                'required' => false,
                'route' => 'portal.zatca.certificates',
                'title' => __('Connect :till to ZATCA', ['till' => $this->tills->label($unit)]),
                'detail' => __('Optional: each till is its own device, onboarded with its own one-time code. Until then head office signs for its branch.'),
                'member' => false,
            ];
        }

        return $steps;
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
