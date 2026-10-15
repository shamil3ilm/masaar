<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Http\Controllers;

use App\Domains\Invoice\Http\Requests\CreatePortalInvoiceRequest;
use App\Domains\Invoice\Services\InvoiceDrafter;
use App\Domains\Organization\Http\Middleware\PortalTenant;
use App\Domains\Organization\Services\TenantResolver;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * Writing an invoice, in a browser.
 *
 * The engine could already do this - InvoiceDrafter, InvoiceTotals and the
 * validation rules are the same ones the API and the ERP pipeline use - and
 * there was no screen for any of it. A taxpayer without a POS integration had
 * no way to produce an invoice at all.
 *
 * A draft and nothing more. Issuing is what allocates the counter, writes the
 * hash and makes the document real, and it is a separate decision on a
 * separate screen for that reason: a half-finished invoice should be editable,
 * and an issued one is part of a chain that cannot be rewritten.
 *
 * The organization comes from PortalTenant, never from the form. A tenant
 * identifier accepted from input is cross-tenant disclosure however carefully
 * the rest is written.
 */
class PortalInvoiceDraftController extends Controller
{
    public function __construct(
        private readonly TenantResolver $tenant,
        private readonly InvoiceDrafter $drafter,
    ) {}

    public function create(Request $request): View|RedirectResponse
    {
        if (! $request->attributes->get(PortalTenant::ORG_ID)) {
            return redirect()->route('portal.invoices.index');
        }

        return view('portal.invoices.create', [
            'organization' => $this->tenant->getOrganization(),
        ]);
    }

    public function store(CreatePortalInvoiceRequest $request): RedirectResponse
    {
        $orgId = $request->attributes->get(PortalTenant::ORG_ID);

        if (! $orgId) {
            return redirect()->route('portal.invoices.index');
        }

        try {
            $invoice = $this->drafter->draft($request->validated(), (string) $orgId);
        } catch (\Throwable $e) {
            // bootstrap/app.php registers the exception renderer only for
            // api/* and v1/*, so anything raised here is an error page rather
            // than a message. The taxpayer has just typed an invoice; losing
            // it to a stack trace is the worst outcome available, so the input
            // goes back with them.
            Log::error('Portal invoice draft failed', [
                'org_id' => $orgId,
                'exception' => $e->getMessage(),
            ]);

            return back()
                ->withInput()
                ->with('error', 'The invoice could not be saved. Nothing was written, and what you typed is still here. If this keeps happening, the detail is in the application log.');
        }

        return redirect()
            ->route('portal.invoices.show', $invoice->id)
            ->with('success', ($invoice->invoice_number === null ? 'Draft saved. It is numbered' : 'Invoice '.$invoice->invoice_number.' saved as a draft. It has no counter and no hash').' until it is issued.');
    }
}
