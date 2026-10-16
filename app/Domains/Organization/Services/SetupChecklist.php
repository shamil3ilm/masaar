<?php

declare(strict_types=1);

namespace App\Domains\Organization\Services;

use App\Domains\Compliance\Fatoora\Exceptions\FatooraException;
use App\Domains\Compliance\Fatoora\Services\FilingClearance;
use App\Domains\Invoice\Models\Customer;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Models\InvoiceSequence;
use App\Domains\Invoice\Models\Product;
use App\Domains\Organization\Models\Organization;

/**
 * What a new organization still has to do before it invoices, in order.
 *
 * A company that signed up landed on a dashboard of zeros and a red banner
 * saying it had no certificate - true, and no help, because nothing said
 * that connecting to ZATCA was the next step or where to do it.
 *
 * Optional steps are listed but do not keep the list on screen: once the
 * required ones are done it goes away.
 */
class SetupChecklist
{
    public function __construct(
        private readonly FilingClearance $clearance,
    ) {}

    /**
     * @return list<array{key: string, done: bool, required: bool, route: string|null}>
     */
    public function steps(Organization $organization): array
    {
        $id = (string) $organization->id;

        return [
            ['key' => 'company', 'done' => $organization->hasCompleteZatcaProfile(), 'required' => true, 'route' => 'portal.company.edit'],
            ['key' => 'approved', 'done' => $this->approved($organization), 'required' => true, 'route' => null],
            ['key' => 'zatca', 'done' => $organization->zatca_onboarded, 'required' => true, 'route' => 'portal.zatca.show'],
            ['key' => 'numbering', 'done' => $this->numbered($id), 'required' => false, 'route' => 'portal.numbering.edit'],
            ['key' => 'catalogue', 'done' => $this->catalogued($id), 'required' => false, 'route' => 'portal.customers.index'],
            ['key' => 'invoice', 'done' => $this->invoiced($id), 'required' => true, 'route' => 'portal.invoices.create'],
        ];
    }

    /**
     * Whether any required step is left, which is when the list is shown.
     *
     * @param  list<array{key: string, done: bool, required: bool, route: string|null}>  $steps
     */
    public function unfinished(array $steps): bool
    {
        foreach ($steps as $step) {
            if ($step['required'] && ! $step['done']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Approved, with a plan: what issuing and onboarding check.
     */
    private function approved(Organization $organization): bool
    {
        try {
            $this->clearance->assertCleared($organization);

            return true;
        } catch (FatooraException) {
            return false;
        }
    }

    /**
     * Numbering was looked at, or the defaults have already been used.
     */
    private function numbered(string $organizationId): bool
    {
        return InvoiceSequence::withoutTenantScope(fn () => InvoiceSequence::query()->where('org_id', $organizationId)->exists())
            || $this->invoiced($organizationId);
    }

    private function catalogued(string $organizationId): bool
    {
        return Customer::withoutTenantScope(fn () => Customer::query()->where('org_id', $organizationId)->exists())
            || Product::withoutTenantScope(fn () => Product::query()->where('org_id', $organizationId)->exists());
    }

    private function invoiced(string $organizationId): bool
    {
        return Invoice::withoutTenantScope(fn () => Invoice::query()->where('org_id', $organizationId)->whereNotNull('hash')->exists());
    }
}
