<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Http\Controllers;

use App\Domains\Compliance\Engine\DTOs\IssueOptions;
use App\Domains\Compliance\Engine\Services\ComplianceEngine;
use App\Domains\Invoice\Enums\InvoiceStatus;
use App\Domains\Invoice\Services\InvoiceFinder;
use App\Domains\Invoice\Services\NoteIssuing;
use App\Domains\Organization\Http\Middleware\PortalTenant;
use App\Domains\Organization\Services\TenantResolver;
use App\Domains\Platform\Access\Access;
use App\Domains\Platform\Access\Feature;
use App\Domains\Platform\Approvals\OrganizationApprovals;
use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Issuing, from the browser. Printing is PortalInvoicePrintController's.
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
        private readonly Access $access,
        private readonly NoteIssuing $notes,
        private readonly OrganizationApprovals $approvals,
    ) {}

    public function issue(Request $request, string $invoiceId): RedirectResponse
    {
        $orgId = $request->attributes->get(PortalTenant::ORG_ID);

        if (! $orgId) {
            return redirect()->route('portal.invoices.index');
        }

        $invoice = $this->invoices->findWithLines($orgId, $invoiceId);
        $organization = $this->tenant->getOrganization();

        // Someone who only reads at this invoice's branch does not issue there.
        abort_if(Gate::denies('issue', $invoice), 403, __('You cannot issue invoices at this branch.'));

        // Sent again - Back, or a second tab - after it was issued: said in
        // the taxpayer's words. The issuing step refuses it too.
        if (! $invoice->isEditable()) {
            return redirect()->route('portal.invoices.show', $invoiceId)
                ->with('error', __('This invoice is already issued and cannot be issued again, so nothing was changed. To correct it, issue a credit or debit note.'));
        }

        // Access answers first: a kill switch, the country, the plan, the
        // company's review or a platform admin's switch for it. A refusal is
        // said with its reason and the draft stays a draft. A country this
        // deployment does not issue in - switched off on the admin console's
        // Countries page, or off by default - is worded as it always was; the
        // layout's banner says it on every page. The country's issuing step
        // still checks the same switches, on every path.
        $decision = $this->access->decide($organization, $request->user(), Feature::IssueInvoices);

        if (! $decision->allowed) {
            return redirect()
                ->route('portal.invoices.show', $invoiceId)
                ->with('error', $decision->code === 'country_off' || $decision->code === 'no_module'
                    ? __('E-invoicing in your country is switched off on this platform, so the invoice was not issued. It stays a draft until it is switched back on.')
                    : __('The invoice was not issued: :reason', ['reason' => $decision->reason]));
        }

        // A note the organization wants a second person on waits for one
        // (NoteIssuing); with nobody else who could approve it, it is issued
        // and the record says so.
        if ($this->notes->holds($invoice, $organization)) {
            try {
                $held = $this->approvals->submit($organization, NoteIssuing::ACTION, $this->notes->payload($invoice), $request->user(), (string) $invoice->adjustment_reason);
            } catch (DomainException $e) {
                return redirect()->route('portal.invoices.show', $invoiceId)->with('error', $e->getMessage());
            }

            return $held->waits()
                ? redirect()->route('portal.invoices.show', $invoiceId)->with('success', __('The note waits for approval by another owner or admin. It is issued once approved.'))
                : redirect()->route('portal.invoices.show', $invoiceId)->with('warning', __('Nobody else in your organization can approve notes, so this one was issued without a second person. It is on the activity record.').' '.$held->result?->message);
        }

        $jurisdiction = $this->engine->jurisdictionFor($organization);

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

        // Handed to a queue rather than answered: it is not filed yet, and
        // saying so was telling the owner something untrue.
        if ($outcome->queued()) {
            return redirect()
                ->route('portal.invoices.show', $invoiceId)
                ->with('success', __('Issued. Filing is queued and will happen automatically; the invoice shows when it is accepted.'));
        }

        // Worded by what happened. A rejection is the authority's verdict,
        // which the same document earns again: it is not retried. Anything
        // else that did not get through says so, and its page says what next.
        if ($outcome->errors !== []) {
            return redirect()
                ->route('portal.invoices.show', $invoiceId)
                ->with('warning', $invoice->fresh()?->status === InvoiceStatus::Rejected
                    ? __('Issued, and the authority rejected it: :error A rejected invoice is not sent again. Issue a corrected invoice instead.', ['error' => $outcome->errors[0]])
                    : __('Issued, and filing did not succeed: :error The invoice keeps its counter; its page says what happens next.', ['error' => $outcome->errors[0]]));
        }

        // Accepted, with advice: said here, and listed on the invoice.
        if ($outcome->warnings !== []) {
            return redirect()
                ->route('portal.invoices.show', $invoiceId)
                ->with('warning', __('Issued and filed, with warnings from the authority. They do not stop the invoice; they are listed on it.'));
        }

        return redirect()
            ->route('portal.invoices.show', $invoiceId)
            ->with('success', $jurisdiction->notices()->filed());
    }
}
