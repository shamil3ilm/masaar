<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Http\Controllers;

use App\Domains\Compliance\Engine\DTOs\IssueOptions;
use App\Domains\Compliance\Engine\Exceptions\JurisdictionUnavailableException;
use App\Domains\Compliance\Engine\Services\ComplianceEngine;
use App\Domains\Invoice\Services\InvoiceFinder;
use App\Domains\Invoice\Services\InvoicePdf;
use App\Domains\Organization\Http\Middleware\PortalTenant;
use App\Domains\Organization\Services\TenantResolver;
use App\Http\Controllers\Controller;
use App\Support\QrCodeRenderer;
use DomainException;
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
 * Issued through the compliance engine, in the organization's jurisdiction.
 * Filing is attempted only when that jurisdiction says the organization is
 * ready to file: for Saudi Arabia, onboarded. A Saudi invoice issued without
 * a certificate is still valid - five TLV tags, no cryptographic ones - and
 * the screen says it has not been filed rather than pretending.
 */
class PortalInvoiceFilingController extends Controller
{
    public function __construct(
        private readonly TenantResolver $tenant,
        private readonly InvoiceFinder $invoices,
        private readonly ComplianceEngine $engine,
        private readonly QrCodeRenderer $qr,
        private readonly InvoicePdf $pdf,
    ) {}

    public function issue(Request $request, string $invoiceId): RedirectResponse
    {
        $orgId = $request->attributes->get(PortalTenant::ORG_ID);

        if (! $orgId) {
            return redirect()->route('portal.invoices.index');
        }

        $invoice = $this->invoices->findWithLines($orgId, $invoiceId);
        $organization = $this->tenant->getOrganization();

        // A country this deployment does not issue in answers as if the
        // action did not exist.
        try {
            $jurisdiction = $this->engine->jurisdictionFor($organization);
        } catch (JurisdictionUnavailableException) {
            abort(404);
        }

        $outcome = $this->engine->issue($invoice, $organization, new IssueOptions(
            file: $jurisdiction->transmission()->readyToFile($organization),
        ));

        if (! $outcome->issued) {
            // Covers both a failure and a refusal - a re-issue, or the
            // country's rules: each reaches here as an error like any other,
            // already worded for a person to read.
            return redirect()
                ->route('portal.invoices.show', $invoiceId)
                ->with('error', $outcome->errors[0] ?? __('The invoice could not be issued.'));
        }

        if (! $outcome->attemptedFiling) {
            return redirect()
                ->route('portal.invoices.show', $invoiceId)
                ->with('warning', $jurisdiction->notices()->notFiled());
        }

        if ($outcome->errors !== []) {
            return redirect()
                ->route('portal.invoices.show', $invoiceId)
                ->with('warning', __('Issued, and filing did not succeed: :error The invoice keeps its counter and will be retried.', ['error' => $outcome->errors[0]]));
        }

        return redirect()
            ->route('portal.invoices.show', $invoiceId)
            ->with('success', $jurisdiction->notices()->filed());
    }

    public function print(Request $request, string $invoiceId): View|RedirectResponse
    {
        $orgId = $request->attributes->get(PortalTenant::ORG_ID);

        if (! $orgId) {
            return redirect()->route('portal.invoices.index');
        }

        $invoice = $this->invoices->findWithLines($orgId, $invoiceId);

        // The same gate as the PDF: a printed copy is a copy handed to the
        // buyer, so a draft or an uncleared standard invoice has none.
        try {
            $this->pdf->assertShareable($invoice);
        } catch (DomainException $e) {
            return redirect()->route('portal.invoices.show', $invoiceId)->with('error', $e->getMessage());
        }

        return view('portal.invoices.print', [
            'invoice' => $invoice,
            'organization' => $this->tenant->getOrganization(),
            'qr' => $this->qr->svg((string) $invoice->qr_code),
        ]);
    }
}
