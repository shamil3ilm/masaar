<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Http\Controllers;

use App\Domains\Compliance\Fatoora\Services\InvoiceIssuer;
use App\Domains\Compliance\Fatoora\Services\QrCodeRenderer;
use App\Domains\Invoice\Services\InvoiceFinder;
use App\Domains\Organization\Http\Middleware\PortalTenant;
use App\Domains\Organization\Services\TenantResolver;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Issuing and printing, from the browser.
 *
 * Issuing is the irreversible step - a counter, a hash and a place in the
 * chain - so it is a POST behind a confirmation, never a link a browser or a
 * crawler can follow.
 *
 * Filing is attempted only when the organization is onboarded. Without a
 * certificate SubmissionGuard throws CERT_NOT_FOUND, which is not in
 * OfflineFallback's connectivity list, so nothing queues and the exception
 * escapes. A Phase 1 invoice is still valid - five TLV tags, no cryptographic
 * ones - and the screen says it has not been filed rather than pretending.
 */
class PortalInvoiceFilingController extends Controller
{
    public function __construct(
        private readonly TenantResolver $tenant,
        private readonly InvoiceFinder $invoices,
        private readonly InvoiceIssuer $issuer,
        private readonly QrCodeRenderer $qr,
    ) {}

    public function issue(Request $request, string $invoiceId): RedirectResponse
    {
        $orgId = $request->attributes->get(PortalTenant::ORG_ID);

        if (! $orgId) {
            return redirect()->route('portal.invoices.index');
        }

        $invoice = $this->invoices->findWithLines($orgId, $invoiceId);
        $organization = $this->tenant->getOrganization();

        $outcome = $this->issuer->issue(
            $invoice,
            $organization,
            file: (bool) $organization->zatca_onboarded,
        );

        if (! $outcome->issued) {
            // Covers both a signing failure and a refused re-issue: the guard
            // in Submitter::generate() throws, so it reaches here as an error
            // like any other, already worded for a person to read.
            return redirect()
                ->route('portal.invoices.show', $invoiceId)
                ->with('error', $outcome->errors[0] ?? 'The invoice could not be issued.');
        }

        if (! $outcome->attemptedFiling) {
            return redirect()
                ->route('portal.invoices.show', $invoiceId)
                ->with('warning', 'Issued. It has not been filed with ZATCA: this organization has no certificate yet, so complete onboarding before the reporting deadline.');
        }

        if ($outcome->errors !== []) {
            return redirect()
                ->route('portal.invoices.show', $invoiceId)
                ->with('warning', 'Issued, and filing did not succeed: '.$outcome->errors[0].' The invoice keeps its counter and will be retried.');
        }

        return redirect()
            ->route('portal.invoices.show', $invoiceId)
            ->with('success', 'Issued and filed with ZATCA.');
    }

    public function print(Request $request, string $invoiceId): View|RedirectResponse
    {
        $orgId = $request->attributes->get(PortalTenant::ORG_ID);

        if (! $orgId) {
            return redirect()->route('portal.invoices.index');
        }

        $invoice = $this->invoices->findWithLines($orgId, $invoiceId);

        return view('portal.invoices.print', [
            'invoice' => $invoice,
            'organization' => $this->tenant->getOrganization(),
            'qr' => $this->qr->svg((string) $invoice->qr_code),
        ]);
    }
}
