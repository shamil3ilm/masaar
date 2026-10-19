<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Http\Controllers;

use App\Domains\Compliance\Engine\Services\JurisdictionResolver;
use App\Domains\Invoice\Enums\DocumentType;
use App\Domains\Invoice\Http\Requests\CreatePortalInvoiceRequest;
use App\Domains\Invoice\Services\AdjustmentDraft;
use App\Domains\Invoice\Services\Catalogue;
use App\Domains\Invoice\Services\ChargePresets;
use App\Domains\Invoice\Services\InvoiceDrafter;
use App\Domains\Invoice\Services\InvoiceFinder;
use App\Domains\Invoice\Services\MissingText;
use App\Domains\Organization\Enums\MemberRole;
use App\Domains\Organization\Http\Middleware\PortalTenant;
use App\Domains\Organization\Services\BranchAccess;
use App\Domains\Organization\Services\BranchScope;
use App\Domains\Organization\Services\TenantResolver;
use App\Domains\Platform\Customization\Customizations;
use App\Domains\Platform\Customization\Scope;
use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
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
        private readonly JurisdictionResolver $jurisdictions,
        private readonly InvoiceDrafter $drafter,
        private readonly Catalogue $catalogue,
        private readonly InvoiceFinder $invoices,
        private readonly AdjustmentDraft $adjustments,
        private readonly ChargePresets $chargePresets,
        private readonly MissingText $missing,
        private readonly BranchAccess $branchAccess,
        private readonly BranchScope $branchScope,
        private readonly Customizations $customizations,
    ) {}

    /**
     * A credit or debit note, started from the invoice it corrects: the
     * new-invoice form opens filled in from it.
     */
    public function adjust(Request $request, string $invoiceId, string $note): RedirectResponse
    {
        $organizationId = $request->attributes->get(PortalTenant::ORG_ID);

        if (! is_string($organizationId)) {
            return redirect()->route('portal.invoices.index');
        }

        abort_if(MemberRole::readOnly($request->user()?->roleIn($organizationId)), 403, __('Your role in this organization is read-only.'));

        try {
            $original = $this->invoices->findWithLines($organizationId, $invoiceId);
            abort_if(Gate::denies('issue', $original), 403, __('You cannot issue invoices at this branch.'));
            $fields = $this->adjustments->fields($original, DocumentType::from($note));
        } catch (ModelNotFoundException) {
            abort(404);
        } catch (DomainException $e) {
            return redirect()->route('portal.invoices.show', $invoiceId)->with('error', $e->getMessage());
        }

        return redirect()->route('portal.invoices.create')->withInput($fields);
    }

    public function create(Request $request): View|RedirectResponse
    {
        if (! $request->attributes->get(PortalTenant::ORG_ID)) {
            return redirect()->route('portal.invoices.index');
        }

        // A viewer would only be refused on saving (org.writer).
        abort_if(MemberRole::readOnly($request->user()?->roleIn((string) $request->attributes->get(PortalTenant::ORG_ID))), 403, __('Your role in this organization is read-only.'));

        $organizationId = (string) $request->attributes->get(PortalTenant::ORG_ID);
        $organization = $this->tenant->getOrganization();

        // The organization's country's form, which posts to the route its
        // rules are checked on (InvoiceRules::portalView(), portalRoute()).
        $jurisdiction = $this->jurisdictions->forRules($organization);

        return view($jurisdiction->invoiceRules()->portalView(), [
            'organization' => $organization,
            // The tax categories and exemption reasons a line is offered.
            'treatments' => $jurisdiction->vatTreatments(),
            'customers' => $this->catalogue->customerChoices($organizationId),
            'products' => $this->catalogue->productChoices($organizationId),
            // The common charges: the platform's, the organization's and
            // this person's own.
            'chargePresets' => $this->chargePresets->for($organizationId, $request->user()?->getAuthIdentifier()),
            // What the form starts on: this person's own choice, else the
            // organization's or the platform's.
            'pricesIncludeVat' => (bool) $this->customizations->get('invoices.prices_include_vat', Scope::member($organizationId, (string) $request->user()?->getAuthIdentifier())),
        ]);
    }

    public function store(CreatePortalInvoiceRequest $request): RedirectResponse
    {
        $orgId = $request->attributes->get(PortalTenant::ORG_ID);

        if (! $orgId) {
            return redirect()->route('portal.invoices.index');
        }

        // The branch it is issued from: the one chosen in the switcher, or a
        // cashier's own. One they only read at, or one the country would
        // refuse an invoice from, is refused here with the reason, before
        // anything is written.
        try {
            $branchId = $this->branchAccess->draftBranch((string) $request->user()?->getAuthIdentifier(), (string) $orgId, $this->branchScope->selected());
        } catch (DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        $refusal = $branchId === null ? null : $this->jurisdictions->forRules($this->tenant->getOrganization())->devices()->refusal((string) $orgId, $branchId);

        if ($refusal !== null) {
            return back()->withInput()->with('error', $refusal);
        }

        // An organization whose country's form posts elsewhere was sent back
        // to it by CreatePortalInvoiceRequest, before anything was checked.
        // As the country's rules map the request, as the API drafts it.
        try {
            $invoice = $this->drafter->draft($request->draft(), (string) $orgId, $branchId, countryFields: $request->countryFields());
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
                ->with('error', __('The invoice could not be saved. Nothing was written, and what you typed is still here. If this keeps happening, the detail is in the application log.'));
        }

        // After the draft, and never at its expense: the invoice is what was
        // asked for, and keeping the buyer is a convenience on top.
        if ($request->boolean('save_customer')) {
            try {
                $this->catalogue->rememberBuyer((string) $orgId, $request->validated());
            } catch (\Throwable $e) {
                report($e);
            }
        }

        // Saved under a grace period without the Arabic its country requires:
        // said at once, and the invoice's page keeps saying it.
        if ($this->missing->of($invoice, $this->tenant->getOrganization()) !== []) {
            return redirect()
                ->route('portal.invoices.show', $invoice->id)
                ->with('warning', __('Draft saved with Arabic text missing. It is accepted only while your grace period lasts: add the Arabic before then.'));
        }

        return redirect()
            ->route('portal.invoices.show', $invoice->id)
            ->with('success', $invoice->invoice_number === null
                ? __('Draft saved. It is numbered until it is issued.')
                : __('Invoice :number saved as a draft. It has no counter and no hash until it is issued.', ['number' => $invoice->invoice_number]));
    }
}
