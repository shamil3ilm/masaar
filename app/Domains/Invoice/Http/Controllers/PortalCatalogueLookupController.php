<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Http\Controllers;

use App\Domains\Compliance\Engine\Services\JurisdictionResolver;
use App\Domains\Invoice\Models\Customer;
use App\Domains\Invoice\Models\Product;
use App\Domains\Invoice\Services\Catalogue;
use App\Domains\Organization\Http\Concerns\ResolvesPortalOrganization;
use App\Domains\Organization\Services\TenantResolver;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The invoice form's pickers, searching as someone types.
 *
 * A company with a few thousand customers cannot send them all in the page;
 * the picker (x-ui.select with a source, resources/js/combobox.js) asks here
 * and adds what comes back as options, each carrying what it fills in - the
 * same data-fields the page's own options carry. Only the organization in
 * the session answers, and only its active records.
 *
 * A customer fills the buyer fields the organization's country's form has
 * (Presentation::buyerFields). For a product, fields=uae asks for the fields
 * the UAE form fills (its own view names them); anything else, the standard
 * form's.
 */
class PortalCatalogueLookupController extends Controller
{
    use ResolvesPortalOrganization;

    private const LIMIT = 20;

    public function __construct(
        private readonly Catalogue $catalogue,
        private readonly JurisdictionResolver $jurisdictions,
        private readonly TenantResolver $tenant,
    ) {}

    public function customers(Request $request): JsonResponse
    {
        $search = $this->search($request);
        $organization = $this->portalOrganization($this->tenant, $request);
        $found = $search === '' ? collect() : $this->catalogue->findCustomers((string) $organization->id, $search, self::LIMIT);
        $presentation = $this->jurisdictions->forRules($organization)->presentation();

        return response()->json(['data' => $found->map(fn (Customer $customer): array => [
            'value' => (string) $customer->id,
            'label' => $customer->name.($customer->vat_number ? ' - '.$customer->vat_number : ''),
            'search' => (string) $customer->name_ar,
            'fields' => $presentation->buyerFields($customer),
        ])->values()]);
    }

    public function products(Request $request): JsonResponse
    {
        $search = $this->search($request);
        $found = $search === '' ? collect() : $this->catalogue->findProducts($this->portalOrganizationId($request), $search, self::LIMIT);
        $uae = $request->query('fields') === 'uae';

        return response()->json(['data' => $found->map(fn (Product $product): array => [
            'value' => (string) $product->id,
            'label' => $product->name.($product->sku ? ' ('.$product->sku.')' : ''),
            'search' => (string) $product->name_ar,
            'fields' => $uae ? $product->uaeLineFields() : $product->lineFields(),
        ])->values()]);
    }

    private function search(Request $request): string
    {
        return mb_substr(trim((string) $request->query('q', '')), 0, 100);
    }
}
