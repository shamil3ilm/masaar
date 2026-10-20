<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Http\Controllers;

use App\Domains\Compliance\Engine\Contracts\LanguageRules;
use App\Domains\Compliance\Engine\Contracts\VatTreatments;
use App\Domains\Compliance\Engine\Services\JurisdictionResolver;
use App\Domains\Invoice\Exceptions\RecordInUseException;
use App\Domains\Invoice\Models\Product;
use App\Domains\Invoice\Services\Catalogue;
use App\Domains\Invoice\Services\CatalogueRemoval;
use App\Domains\Invoice\Services\CustomerHistory;
use App\Domains\Invoice\Services\ProductHistory;
use App\Domains\Invoice\Support\CatalogueRules;
use App\Domains\Invoice\Support\UnitCode;
use App\Domains\Organization\Http\Concerns\ResolvesPortalOrganization;
use App\Domains\Organization\Services\TenantResolver;
use App\Http\Controllers\Controller;
use App\Http\Listing\Lists;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The organization's products and services, so an invoice line starts filled
 * in.
 *
 * Its Arabic name is what a line naming the product takes where the
 * organization's country requires Arabic (LanguageRules), so there it is
 * required.
 *
 * One an invoice used is only archived; one never used can be removed, and
 * brought back from the list's Removed view (CatalogueRemoval).
 */
class PortalProductController extends Controller
{
    use ResolvesPortalOrganization;

    public function __construct(
        private readonly Catalogue $catalogue,
        private readonly TenantResolver $tenant,
        private readonly JurisdictionResolver $jurisdictions,
        private readonly CatalogueRemoval $removal,
        private readonly ProductHistory $history,
        private readonly Lists $lists,
        private readonly CatalogueRules $rules,
    ) {}

    public function index(Request $request): View
    {
        $organizationId = $this->portalOrganizationId($request);
        $list = $this->lists->portal($this->history->spec('portal.products.index'), $request, $organizationId);

        return view('portal.products.index', [
            'products' => $this->history->page($organizationId, $list),
            'list' => $list,
            'removed' => $list->value('archived') === CustomerHistory::REMOVED,
        ]);
    }

    public function create(): View
    {
        return view('portal.products.form', [
            'product' => new Product(['unit_code' => UnitCode::DEFAULT, 'tax_rate' => 15]),
            'arabicRequired' => $this->language()->requiresArabic(),
            'treatments' => $this->treatments(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $organizationId = $this->portalOrganizationId($request);

        $this->catalogue->saveProduct($organizationId, $this->validated($request));

        return redirect()->route('portal.products.index')->with('success', __('Product saved.'));
    }

    public function edit(Request $request, string $productId): View
    {
        $product = $this->product($request, $productId);

        return view('portal.products.form', [
            'product' => $product,
            'removable' => ! $this->removal->inUse($product),
            'arabicRequired' => $this->language()->requiresArabic(),
            'treatments' => $this->treatments(),
        ]);
    }

    public function update(Request $request, string $productId): RedirectResponse
    {
        $organizationId = $this->portalOrganizationId($request);
        $product = $this->product($request, $productId);

        $this->catalogue->saveProduct($organizationId, $this->validated($request, $product), $product);

        return redirect()->route('portal.products.index')->with('success', __('Product saved.'));
    }

    public function archive(Request $request, string $productId): RedirectResponse
    {
        $product = $this->product($request, $productId);
        $archived = ! $request->boolean('restore');

        $this->catalogue->archive($product, $archived);

        return redirect()->route('portal.products.index')
            ->with('success', $archived ? __(':name archived. Invoices already written keep their lines.', ['name' => $product->name]) : __(':name restored.', ['name' => $product->name]));
    }

    /** Remove a product no invoice has used; one that was used is archived instead. */
    public function remove(Request $request, string $productId): RedirectResponse
    {
        $product = $this->product($request, $productId);

        try {
            $this->removal->remove($product);
        } catch (RecordInUseException $e) {
            return redirect()->route('portal.products.edit', $productId)->with('error', $e->getMessage());
        }

        return redirect()->route('portal.products.index')
            ->with('success', __(':name removed. Show the removed ones in the list to bring it back.', ['name' => $product->name]));
    }

    /** Bring back a removed product, from the list's Removed view. */
    public function restoreRemoved(Request $request, string $productId): RedirectResponse
    {
        $product = $this->removal->removedProduct($this->portalOrganizationId($request), $productId) ?? abort(404);

        try {
            $this->removal->restore($product);
        } catch (DomainException $e) {
            return redirect()->route('portal.products.index', ['archived' => CustomerHistory::REMOVED])->with('error', $e->getMessage());
        }

        return redirect()->route('portal.products.index')->with('success', __(':name restored.', ['name' => $product->name]));
    }

    /**
     * As CatalogueRules has them for the organization's country: the rules
     * the CSV import checks a row by too.
     *
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Product $product = null): array
    {
        $organization = $this->tenant->getOrganization();
        [$rules, $messages] = $this->rules->product($organization, $request, $product);

        return $this->rules->keptProduct($organization, $request->validate($rules, $messages));
    }

    private function treatments(): VatTreatments
    {
        return $this->jurisdictions->forRules($this->tenant->getOrganization())->vatTreatments();
    }

    /** The organization's country's language rules, switched on or not. */
    private function language(): LanguageRules
    {
        return $this->jurisdictions->forRules($this->tenant->getOrganization())->languageRules();
    }

    private function product(Request $request, string $productId): Product
    {
        return $this->catalogue->product($this->portalOrganizationId($request), $productId) ?? abort(404);
    }
}
