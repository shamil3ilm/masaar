<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Http\Controllers;

use App\Domains\Compliance\Engine\Services\JurisdictionResolver;
use App\Domains\Invoice\Services\InvoicePreview;
use App\Domains\Organization\Services\TenantResolver;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The new-invoice form's running figures: each line's VAT and the VAT by
 * tax category, worked out by the server as the draft will be
 * (InvoicePreview), so the form shows what it is saved with. Read-only:
 * nothing is written, and the form saves without it.
 */
class PortalInvoicePreviewController extends Controller
{
    public function __construct(
        private readonly TenantResolver $tenant,
        private readonly JurisdictionResolver $jurisdictions,
        private readonly InvoicePreview $preview,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $treatments = $this->jurisdictions->forRules($this->tenant->getOrganization())->vatTreatments();

        return response()->json($this->preview->of($request->only(['lines', 'charges', 'discount_amount', 'prices_include_vat']), $treatments));
    }
}
