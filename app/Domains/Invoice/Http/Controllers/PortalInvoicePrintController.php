<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Http\Controllers;

use App\Domains\Invoice\Services\InvoiceFinder;
use App\Domains\Invoice\Services\InvoicePrintView;
use App\Domains\Organization\Http\Middleware\PortalTenant;
use App\Domains\Organization\Services\PrintPreferences;
use App\Domains\Organization\Services\TenantResolver;
use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
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
        private readonly PrintPreferences $preferences,
    ) {}

    public function print(Request $request, string $invoiceId): View|RedirectResponse
    {
        $orgId = $request->attributes->get(PortalTenant::ORG_ID);

        if (! $orgId) {
            return redirect()->route('portal.invoices.index');
        }

        $invoice = $this->invoices->findWithLines($orgId, $invoiceId);
        $organization = $this->tenant->getOrganization() ?? abort(404);
        // The paper chosen on this screen, else the person's, the till's or
        // the organization's.
        $paper = $this->preferences->paper($organization, $invoice->branch_id, $request->user()?->id, (string) $request->query('paper'));

        // The same gate as the PDF: a printed copy is a copy handed to the
        // buyer, so one the country would not hand over has none.
        try {
            return view('portal.invoices.print', $this->printView->data($invoice, $organization, $paper));
        } catch (DomainException $e) {
            return redirect()->route('portal.invoices.show', $invoiceId)->with('error', $e->getMessage());
        }
    }

    /**
     * The country's QR code as a PNG or SVG file, to put on a sign or send
     * on: a copy of the invoice's QR, under the printed copy's gate.
     */
    public function qr(Request $request, string $invoiceId, string $format): Response|RedirectResponse
    {
        $orgId = $request->attributes->get(PortalTenant::ORG_ID);
        abort_unless(is_string($orgId), 404);

        $invoice = $this->invoices->findWithLines($orgId, $invoiceId);
        $organization = $this->tenant->getOrganization() ?? abort(404);

        try {
            $file = $this->printView->qrFile($invoice, $organization, $format);
        } catch (DomainException $e) {
            return redirect()->route('portal.invoices.show', $invoiceId)->with('error', $e->getMessage());
        }

        abort_if($file === '', 404);
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $invoice->invoice_number);

        return response($file, 200, [
            'Content-Type' => $format === 'svg' ? 'image/svg+xml' : 'image/png',
            'Content-Disposition' => 'attachment; filename="'.trim((string) $name, '-').'-qr.'.$format.'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
