<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Http\Controllers;

use App\Domains\Compliance\Engine\Services\JurisdictionResolver;
use App\Domains\Invoice\Services\Catalogue;
use App\Domains\Invoice\Services\InvoiceFinder;
use App\Domains\Organization\Http\Middleware\PortalTenant;
use App\Domains\Organization\Services\Membership;
use App\Domains\Organization\Services\TenantResolver;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

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
    /**
     * Enough to see a page of invoices without scrolling past the filters.
     */
    private const PER_PAGE = 25;

    public function __construct(
        private readonly TenantResolver $tenant,
        private readonly Membership $membership,
        private readonly InvoiceFinder $invoices,
        private readonly Catalogue $catalogue,
        private readonly JurisdictionResolver $jurisdictions,
    ) {}

    public function index(Request $request): View
    {
        $orgId = $request->attributes->get(PortalTenant::ORG_ID);

        if (! $orgId) {
            return $this->organizationPicker();
        }

        $status = $this->given($request->query('status'));
        $search = $this->given($request->query('q'));

        return view('portal.invoices.index', [
            'organization' => $this->tenant->getOrganization(),
            'invoices' => $this->invoices
                ->search($orgId, $status, $search, self::PER_PAGE)
                ->withQueryString(),
            'status' => $status,
            'search' => $search,
        ]);
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

        $organization = $this->tenant->getOrganization();

        return view('portal.invoices.show', [
            'organization' => $organization,
            'invoice' => $invoice,
            'sendTo' => $this->catalogue->emailFor((string) $orgId, $invoice->buyer_vat_number),
            'page' => $this->jurisdictions->forRules($organization)->presentation()->invoicePage($invoice),
        ]);
    }

    /**
     * A query value that was actually given.
     *
     * A malformed `?status[]=x` arrives as an array, and passing that to a
     * where() is a type error rather than an empty result. Anything that is
     * not a non-empty string narrows nothing.
     */
    private function given(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
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
