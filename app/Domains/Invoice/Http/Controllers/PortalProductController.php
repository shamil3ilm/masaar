<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Http\Controllers;

use App\Domains\Invoice\Models\Product;
use App\Domains\Invoice\Services\Catalogue;
use App\Domains\Invoice\Support\UnitCode;
use App\Domains\Organization\Http\Concerns\ResolvesPortalOrganization;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The organization's products and services, so an invoice line starts filled
 * in.
 */
class PortalProductController extends Controller
{
    use ResolvesPortalOrganization;

    private const PER_PAGE = 25;

    public function __construct(
        private readonly Catalogue $catalogue,
    ) {}

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q', ''));
        $archived = $request->boolean('archived');

        return view('portal.products.index', [
            'products' => $this->catalogue->products($this->portalOrganizationId($request), $search === '' ? null : $search, $archived, self::PER_PAGE)->withQueryString(),
            'search' => $search,
            'archived' => $archived,
        ]);
    }

    public function create(): View
    {
        return view('portal.products.form', ['product' => new Product(['unit_code' => UnitCode::DEFAULT, 'tax_rate' => 15])]);
    }

    public function store(Request $request): RedirectResponse
    {
        $organizationId = $this->portalOrganizationId($request);

        $this->catalogue->saveProduct($organizationId, $this->validated($request, $organizationId));

        return redirect()->route('portal.products.index')->with('success', __('Product saved.'));
    }

    public function edit(Request $request, string $productId): View
    {
        return view('portal.products.form', ['product' => $this->product($request, $productId)]);
    }

    public function update(Request $request, string $productId): RedirectResponse
    {
        $organizationId = $this->portalOrganizationId($request);
        $product = $this->product($request, $productId);

        $this->catalogue->saveProduct($organizationId, $this->validated($request, $organizationId, $product), $product);

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

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, string $organizationId, ?Product $product = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'name_ar' => ['nullable', 'string', 'max:255'],
            'sku' => [
                'nullable', 'string', 'max:64',
                Rule::unique('products', 'sku')->where('org_id', $organizationId)->ignore($product?->id),
            ],
            'unit_code' => ['required', 'string', Rule::in(UnitCode::accepted())],
            'unit_price' => ['required', 'numeric', 'min:0', 'max:9999999999'],
            'tax_rate' => ['required', 'numeric', 'min:0', 'max:100'],
        ], [
            'sku.unique' => __('Another product already has this code.'),
        ]);
    }

    private function product(Request $request, string $productId): Product
    {
        return $this->catalogue->product($this->portalOrganizationId($request), $productId) ?? abort(404);
    }
}
