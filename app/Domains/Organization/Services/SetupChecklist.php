<?php

declare(strict_types=1);

namespace App\Domains\Organization\Services;

use App\Domains\Compliance\Engine\Services\JurisdictionResolver;
use App\Domains\Invoice\Models\Customer;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Models\InvoiceSequence;
use App\Domains\Invoice\Models\Product;
use App\Domains\Organization\Models\Organization;
use App\Support\Errors\ComplianceException;

/**
 * What a new organization still has to do before it invoices, in order.
 *
 * The steps every country shares - approval, numbering, the catalogue - are
 * here; the seller's details, what the country registers with its authority
 * and the first invoice are the country's (SetupSteps, from the
 * organization's module), with what the dashboard says about each.
 *
 * Optional steps are listed but do not keep the list on screen: once the
 * required ones are done it goes away.
 */
class SetupChecklist
{
    public function __construct(
        private readonly FilingClearance $clearance,
        private readonly JurisdictionResolver $jurisdictions,
    ) {}

    /**
     * The steps of an organization by its id, for the admin console; none
     * for one that does not exist.
     *
     * @return list<array{key: string, done: bool, required: bool, route: string|null, title: string, detail: string, member: bool}>
     */
    public function stepsFor(string $organizationId): array
    {
        $organization = Organization::query()->find($organizationId);

        return $organization === null ? [] : $this->steps($organization);
    }

    /**
     * @return list<array{key: string, done: bool, required: bool, route: string|null, title: string, detail: string, member: bool}>
     */
    public function steps(Organization $organization): array
    {
        $id = (string) $organization->id;
        $country = $this->jurisdictions->forRules($organization)->setup();

        return [
            $country->company($organization),
            [
                'key' => 'approved', 'done' => $this->approved($organization), 'required' => true, 'route' => null,
                'title' => __('Wait for approval'),
                'detail' => __('We review new companies and start their plan, usually within one working day.'),
                'member' => false,
            ],
            ...$country->onboarding($organization),
            [
                'key' => 'numbering', 'done' => $this->numbered($id), 'required' => false, 'route' => 'portal.numbering.edit',
                'title' => __('Check how invoices are numbered'),
                'detail' => __('Optional: the defaults work, or set your own prefix and pattern.'),
                'member' => false,
            ],
            [
                'key' => 'catalogue', 'done' => $this->catalogued($id), 'required' => false, 'route' => 'portal.customers.index',
                'title' => __('Add your customers and products'),
                'detail' => __('Optional: so invoices fill themselves in.'),
                'member' => true,
            ],
            $country->firstInvoice($organization),
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
        } catch (ComplianceException) {
            return false;
        }
    }

    /**
     * Numbering was looked at, or the defaults have already been used: an
     * invoice with a hash has its number.
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
