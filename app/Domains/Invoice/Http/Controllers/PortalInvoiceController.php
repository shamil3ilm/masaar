<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Http\Controllers;

use App\Domains\Compliance\Engine\Services\JurisdictionResolver;
use App\Domains\Invoice\Services\Catalogue;
use App\Domains\Invoice\Services\InvoiceFinder;
use App\Domains\Invoice\Services\InvoiceHistory;
use App\Domains\Invoice\Services\MissingText;
use App\Domains\Organization\Http\Middleware\PortalTenant;
use App\Domains\Organization\Services\Membership;
use App\Domains\Organization\Services\TenantResolver;
use App\Http\Controllers\Controller;
use App\Http\Listing\Lists;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The taxpayer's own invoices, in a browser.
 *
 * Until this existed there was no invoice surface at all: every invoice the
 * API, the ERP pipeline or a partner created was invisible to the taxpayer who
 * owned it, and the only way to read one back was another API call.
 *
 * The organization is resolved by PortalTenant from the session's active
 * memberships. Nothing here reads a tenant identifier from query, body or
 * header - doing so reopens cross-tenant disclosure.
 *
 * Invoices are looked up through InvoiceFinder, which names the organization
 * in the query as well as relying on BelongsToTenant's global scope. Route
 * model binding would rely on the scope alone, and the scope stands down in
 * console context - so a foreign id must be unfindable by the query itself,
 * not merely by a scope that is sometimes absent.
 *
 * What an invoice's page shows beyond the invoice itself - what it is
 * called, what can be done with it, where it stands with the authority or
 * the network - is its country's (Presentation::invoicePage).
 */
class PortalInvoiceController extends Controller
{
    public function __construct(
        private readonly TenantResolver $tenant,
        private readonly Membership $membership,
        private readonly InvoiceFinder $invoices,
        private readonly InvoiceHistory $history,
        private readonly Lists $lists,
        private readonly Catalogue $catalogue,
        private readonly JurisdictionResolver $jurisdictions,
        private readonly MissingText $missing,
    ) {}

    /**
     * Every document, sorted and narrowed as the query string asks
     * (InvoiceHistory): the filters live in the link, so a filtered list
     * can be bookmarked or sent to a colleague.
     */
    public function index(Request $request): View
    {
        return $this->list($request, 'portal.invoices.index', 'portal.invoices.export', notes: false);
    }

    /** The credit and debit notes alone, with the same toolkit. */
    public function notes(Request $request): View
    {
        return $this->list($request, 'portal.notes.index', 'portal.notes.export', notes: true);
    }

    /** Exactly the invoices the list's filters give, as a CSV. */
    public function export(Request $request): StreamedResponse
    {
        return $this->csv($request, 'portal.invoices.index', 'invoices', notes: false);
    }

    public function exportNotes(Request $request): StreamedResponse
    {
        return $this->csv($request, 'portal.notes.index', 'notes', notes: true);
    }

    public function show(Request $request, string $invoiceId): View
    {
        $orgId = $request->attributes->get(PortalTenant::ORG_ID);

        if (! $orgId) {
            return $this->organizationPicker();
        }

        try {
            $invoice = $this->invoices->findWithLines($orgId, $invoiceId);
        } catch (ModelNotFoundException) {
            // Another tenant's invoice and an invoice that does not exist are
            // the same answer on purpose: a 404 that differed would confirm
            // the id belongs to someone.
            abort(404);
        }

        // InvoiceFinder has already left out another branch's invoice; the
        // policy says the same rule where it is asked (InvoicePolicy).
        abort_if(Gate::denies('view', $invoice), 404);

        $organization = $this->tenant->getOrganization();
        $jurisdiction = $this->jurisdictions->forRules($organization);

        return view('portal.invoices.show', [
            'organization' => $organization,
            'invoice' => $invoice,
            'sendTo' => $this->catalogue->emailFor((string) $orgId, $invoice->buyer_vat_number),
            'page' => $jurisdiction->presentation()->invoicePage($invoice),
            // The warnings the authority accepted it with, never dropped.
            'advisories' => $jurisdiction->notices()->advisories($invoice),
            // And, rejected, each rule it broke.
            'rejections' => $jurisdiction->notices()->rejections($invoice),
            // What a grace period let through without its Arabic: noted on
            // this page, never on the copy the buyer is given.
            'missingText' => $this->missing->of($invoice, $organization),
        ]);
    }

    private function list(Request $request, string $route, string $export, bool $notes): View
    {
        $orgId = $request->attributes->get(PortalTenant::ORG_ID);

        if (! $orgId) {
            return $this->organizationPicker();
        }

        $organization = $this->tenant->getOrganization() ?? abort(404);
        $list = $this->lists->portal($this->history->spec($organization, $route, $notes), $request, (string) $orgId, $export);

        return view('portal.invoices.index', [
            'organization' => $organization,
            'invoices' => $this->history->page((string) $orgId, $list, $notes),
            'list' => $list,
            'notes' => $notes,
            'documents' => InvoiceHistory::documentLabels(),
        ]);
    }

    private function csv(Request $request, string $route, string $name, bool $notes): StreamedResponse
    {
        $orgId = $request->attributes->get(PortalTenant::ORG_ID);
        $organization = is_string($orgId) ? $this->tenant->getOrganization() : null;

        abort_if($organization === null, 404);

        $list = $this->lists->portal($this->history->spec($organization, $route, $notes), $request, $orgId);

        return $this->history->csv($organization, $list, $name.'-'.now()->format('Y-m-d').'.csv', $notes);
    }

    /**
     * Shown when a multi-organization user has not chosen one yet. The
     * middleware has already rejected any they cannot reach.
     */
    private function organizationPicker(): View
    {
        return view('portal.select-org', [
            'organizations' => $this->membership->organizations(Auth::user()),
            'error' => null,
        ]);
    }
}
