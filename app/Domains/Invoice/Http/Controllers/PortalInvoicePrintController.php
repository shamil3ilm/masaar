<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Http\Controllers;

use App\Domains\Invoice\Enums\PaperSize;
use App\Domains\Invoice\Services\InvoiceFinder;
use App\Domains\Invoice\Services\InvoicePrintView;
use App\Domains\Organization\Http\Middleware\PortalTenant;
use App\Domains\Organization\Services\TenantResolver;
use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * An issued invoice printed from the browser, on the paper chosen.
 */
class PortalInvoicePrintController extends Controller
{
    public function __construct(
        private readonly TenantResolver $tenant,
        private readonly InvoiceFinder $invoices,
        private readonly InvoicePrintView $printView,
    ) {}

    public function print(Request $request, string $invoiceId): View|RedirectResponse
    {
        $orgId = $request->attributes->get(PortalTenant::ORG_ID);

        if (! $orgId) {
            return redirect()->route('portal.invoices.index');
        }

        $invoice = $this->invoices->findWithLines($orgId, $invoiceId);
        $organization = $this->tenant->getOrganization() ?? abort(404);
        $paper = PaperSize::tryFrom((string) $request->query('paper')) ?? PaperSize::A4;

        // The same gate as the PDF: a printed copy is a copy handed to the
        // buyer, so one the country would not hand over has none.
        try {
            return view('portal.invoices.print', $this->printView->data($invoice, $organization, $paper));
        } catch (DomainException $e) {
            return redirect()->route('portal.invoices.show', $invoiceId)->with('error', $e->getMessage());
        }
    }
}
