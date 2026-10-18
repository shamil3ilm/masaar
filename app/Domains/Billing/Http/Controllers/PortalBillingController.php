<?php

declare(strict_types=1);

namespace App\Domains\Billing\Http\Controllers;

use App\Domains\Billing\Http\BillingInvoiceResponse;
use App\Domains\Billing\Services\Billing;
use App\Domains\Invoice\Services\InvoicePdf;
use App\Domains\Organization\Enums\MemberRole;
use App\Domains\Organization\Http\Concerns\ResolvesPortalOrganization;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * An organization's plan, its usage this month, and what it has paid.
 *
 * Its owners and administrators, as with the company's other settings, and
 * its accountants, who read it and download the plan's invoices.
 */
class PortalBillingController extends Controller
{
    use ResolvesPortalOrganization;

    public function __construct(
        private readonly Billing $billing,
        private readonly InvoicePdf $pdf,
    ) {}

    public function show(Request $request): View
    {
        $organizationId = $this->organizationId($request);
        $licence = $this->billing->licenceOf($organizationId);

        return view('portal.billing', [
            'licence' => $licence,
            'usage' => $this->billing->usage($organizationId, $licence),
            'payments' => $this->billing->payments($organizationId),
        ]);
    }

    public function download(Request $request, string $paymentId): Response|RedirectResponse
    {
        $payment = $this->billing->payment($this->organizationId($request), $paymentId) ?? abort(404);

        return BillingInvoiceResponse::for($payment, $this->billing, $this->pdf)
            ?? redirect()->route('portal.billing')->with('error', __('That invoice is not ready yet. It is available once ZATCA has cleared it.'));
    }

    private function organizationId(Request $request): string
    {
        $organizationId = $this->portalOrganizationId($request);
        $role = $request->user()?->roleIn($organizationId);

        abort_unless(MemberRole::administers($role) || $role === MemberRole::Accountant->value, 403, __('Only an administrator of this organization can see its billing.'));

        return $organizationId;
    }
}
