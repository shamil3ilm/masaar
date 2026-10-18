<?php

declare(strict_types=1);

namespace App\Domains\Billing\Http\Controllers;

use App\Domains\Billing\Http\BillingInvoiceResponse;
use App\Domains\Billing\Services\Billing;
use App\Domains\Billing\Services\PaymentHistory;
use App\Domains\Invoice\Services\InvoicePdf;
use App\Domains\Organization\Http\Concerns\ResolvesPortalOrganization;
use App\Domains\Organization\Services\TenantResolver;
use App\Http\Controllers\Controller;
use App\Http\Listing\Lists;
use App\Http\Listing\ListState;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * An organization's plan, its usage this month, and what it has paid.
 *
 * Its administrators only, as with the company's other settings.
 */
class PortalBillingController extends Controller
{
    use ResolvesPortalOrganization;

    public function __construct(
        private readonly Billing $billing,
        private readonly PaymentHistory $history,
        private readonly Lists $lists,
        private readonly TenantResolver $tenant,
        private readonly InvoicePdf $pdf,
    ) {}

    public function show(Request $request): View
    {
        $organizationId = $this->organizationId($request);
        $licence = $this->billing->licenceOf($organizationId);
        $list = $this->list($request, 'portal.billing.export');

        return view('portal.billing', [
            'licence' => $licence,
            'usage' => $this->billing->usage($organizationId, $licence),
            'payments' => $this->history->page($organizationId, $list),
            'list' => $list,
        ]);
    }

    /** Exactly the payments the list's filters give, as a CSV. */
    public function export(Request $request): StreamedResponse
    {
        return $this->history->csv($this->organizationId($request), $this->list($request), 'payments-'.now()->format('Y-m-d').'.csv');
    }

    public function download(Request $request, string $paymentId): Response|RedirectResponse
    {
        $payment = $this->billing->payment($this->organizationId($request), $paymentId) ?? abort(404);

        return BillingInvoiceResponse::for($payment, $this->billing, $this->pdf)
            ?? redirect()->route('portal.billing')->with('error', __('That invoice is not ready yet. It is available once ZATCA has cleared it.'));
    }

    private function list(Request $request, ?string $export = null): ListState
    {
        $organization = $this->portalOrganization($this->tenant, $request, __('Only an administrator of this organization can see its billing.'));

        return $this->lists->portal($this->history->spec($organization, 'portal.billing'), $request, (string) $organization->id, $export);
    }

    private function organizationId(Request $request): string
    {
        return $this->portalOrganizationId($request, __('Only an administrator of this organization can see its billing.'));
    }
}
