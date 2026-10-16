<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Http\Controllers;

use App\Domains\Invoice\Mail\InvoiceMail;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Services\InvoiceFinder;
use App\Domains\Invoice\Services\InvoicePdf;
use App\Domains\Organization\Http\Middleware\PortalTenant;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Services\TenantResolver;
use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * An issued invoice as a PDF: downloaded, or emailed to the buyer.
 */
class PortalInvoiceDocumentController extends Controller
{
    public function __construct(
        private readonly TenantResolver $tenant,
        private readonly InvoiceFinder $invoices,
        private readonly InvoicePdf $pdf,
    ) {}

    public function download(Request $request, string $invoiceId): Response|RedirectResponse
    {
        $invoice = $this->invoice($request, $invoiceId);

        try {
            $pdf = $this->pdf->render($invoice, $this->organization());
        } catch (DomainException $e) {
            return redirect()->route('portal.invoices.show', $invoiceId)->with('error', $e->getMessage());
        }

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$this->pdf->fileName($invoice).'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function send(Request $request, string $invoiceId): RedirectResponse
    {
        $invoice = $this->invoice($request, $invoiceId);
        $email = (string) $request->validate(['email' => ['required', 'email', 'max:255']])['email'];
        $organization = $this->organization();

        try {
            $pdf = $this->pdf->render($invoice, $organization);
        } catch (DomainException $e) {
            return redirect()->route('portal.invoices.show', $invoiceId)->with('error', $e->getMessage());
        }

        try {
            Mail::to($email)->send(new InvoiceMail(
                sellerName: $organization->documentName(),
                invoiceNumber: (string) $invoice->invoice_number,
                total: number_format((float) $invoice->total, 2).' '.$invoice->currency,
                pdf: $pdf,
                fileName: $this->pdf->fileName($invoice),
                // A buyer's answer goes to the person who sent it, not to an
                // address nobody reads.
                replyToAddress: $request->user()?->email,
            ));
        } catch (Throwable $e) {
            Log::error('Invoice email failed', ['invoice_id' => $invoice->id, 'error' => $e->getMessage()]);

            return redirect()->route('portal.invoices.show', $invoiceId)
                ->with('error', __('The email could not be sent. Download the PDF and send it yourself, or try again later.'));
        }

        return redirect()->route('portal.invoices.show', $invoiceId)->with('success', __('Invoice sent to :email.', ['email' => $email]));
    }

    private function invoice(Request $request, string $invoiceId): Invoice
    {
        $organizationId = $request->attributes->get(PortalTenant::ORG_ID);

        abort_unless(is_string($organizationId), 404);

        try {
            return $this->invoices->findWithLines($organizationId, $invoiceId);
        } catch (ModelNotFoundException) {
            abort(404);
        }
    }

    private function organization(): Organization
    {
        return $this->tenant->getOrganization() ?? abort(404);
    }
}
