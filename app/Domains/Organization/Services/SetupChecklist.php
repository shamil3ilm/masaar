<?php

declare(strict_types=1);

namespace App\Domains\Organization\Services;

use App\Domains\Compliance\Engine\Services\JurisdictionResolver;
use App\Domains\Invoice\Models\Customer;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Models\InvoiceSequence;
use App\Domains\Invoice\Models\Product;
use App\Domains\Organization\Models\Organization;
use App\Domains\Platform\Customization\Customizations;
use App\Domains\Platform\Customization\Level;
use App\Domains\Platform\Customization\Scope;
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
 *
 * The organization's business type picks the catalogue steps that suit it
 * (BusinessType::$setupSteps): a shop adds its products and chooses its
 * receipt paper, a wholesaler adds its customers. All optional, so a type
 * never holds anything up.
 */
class SetupChecklist
{
    public function __construct(
        private readonly FilingClearance $clearance,
        private readonly JurisdictionResolver $jurisdictions,
        private readonly BusinessTypes $businessTypes,
        private readonly Customizations $customizations,
    ) {}

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
            ...$this->catalogueSteps($organization),
            $country->firstInvoice($organization),
        ];
    }

    /**
     * The business type's own steps, or the one every organization has.
     *
     * @return list<array{key: string, done: bool, required: bool, route: string|null, title: string, detail: string, member: bool}>
     */
    private function catalogueSteps(Organization $organization): array
    {
        $id = (string) $organization->id;
        $chosen = $this->businessTypes->of($organization)?->setupSteps ?? [];
        $steps = [
            'products' => [
                'key' => 'products', 'done' => $this->hasProducts($id), 'required' => false, 'route' => 'portal.products.index',
                'title' => __('Add your products, with their VAT treatment'),
                'detail' => __('Optional: each line starts filled in, zero-rated or standard-rated as the product is.'),
                'member' => true,
            ],
            'customers' => [
                'key' => 'customers', 'done' => $this->hasCustomers($id), 'required' => false, 'route' => 'portal.customers.index',
                'title' => __('Add your regular customers'),
                'detail' => __('Optional: so an invoice fills in its buyer.'),
                'member' => true,
            ],
            'printing' => [
                'key' => 'printing', 'done' => $this->customizations->own('printing.paper', Level::Organization, Scope::organization($id)) !== null,
                'required' => false, 'route' => 'portal.settings.edit',
                'title' => __('Choose the paper your invoices print on'),
                'detail' => __('Optional: an 80 mm roll for a receipt printer, or A4.'),
                'member' => false,
            ],
        ];

        $picked = array_values(array_intersect_key($steps, array_flip($chosen)));

        return $picked !== [] ? $picked : [[
            'key' => 'catalogue', 'done' => $this->hasCustomers($id) || $this->hasProducts($id), 'required' => false, 'route' => 'portal.customers.index',
            'title' => __('Add your customers and products'),
            'detail' => __('Optional: so invoices fill themselves in.'),
            'member' => true,
        ]];
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

    private function hasCustomers(string $organizationId): bool
    {
        return Customer::withoutTenantScope(fn () => Customer::query()->where('org_id', $organizationId)->exists());
    }

    private function hasProducts(string $organizationId): bool
    {
        return Product::withoutTenantScope(fn () => Product::query()->where('org_id', $organizationId)->exists());
    }

    private function invoiced(string $organizationId): bool
    {
        return Invoice::withoutTenantScope(fn () => Invoice::query()->where('org_id', $organizationId)->whereNotNull('hash')->exists());
    }
}
