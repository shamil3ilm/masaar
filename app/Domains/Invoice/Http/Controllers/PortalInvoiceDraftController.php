<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Http\Controllers;

use App\Domains\Compliance\Engine\Services\JurisdictionResolver;
use App\Domains\Invoice\Enums\DocumentType;
use App\Domains\Invoice\Exceptions\InvoiceNotEditableException;
use App\Domains\Invoice\Http\Requests\CreatePortalInvoiceRequest;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Services\AdjustmentDraft;
use App\Domains\Invoice\Services\Catalogue;
use App\Domains\Invoice\Services\ChargePresets;
use App\Domains\Invoice\Services\DraftForm;
use App\Domains\Invoice\Services\InvoiceDrafter;
use App\Domains\Invoice\Services\InvoiceFinder;
use App\Domains\Invoice\Services\MissingText;
use App\Domains\Organization\Enums\MemberRole;
use App\Domains\Organization\Http\Middleware\PortalTenant;
use App\Domains\Organization\Services\BranchAccess;
use App\Domains\Organization\Services\BranchScope;
use App\Domains\Organization\Services\BusinessTypes;
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
        private readonly BusinessTypes $businessTypes,
        private readonly DraftForm $form,
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
        return $this->form($request);
    }

    /**
     * A draft, back in the form it was written on. Only while it is a draft:
     * an issued invoice is corrected by a note.
     */
    public function edit(Request $request, string $invoiceId): View|RedirectResponse
    {
        $draft = $this->ownDraft($request, $invoiceId);

        if (! $draft->isEditable()) {
            return redirect()->route('portal.invoices.show', $invoiceId)
                ->with('error', __('Only a draft can be changed. An issued invoice is corrected with a credit or debit note.'));
        }

        if (! $this->jurisdictions->forRules($this->tenant->getOrganization())->invoiceRules()->editsDrafts()) {
            return redirect()->route('portal.invoices.show', $invoiceId)
                ->with('error', __('This draft cannot be changed on the form yet. Delete it and write it again.'));
        }

        // Back from a failed save of this edit, what was typed stays.
        if ($request->old(DraftForm::REPLACES) !== $draft->id) {
            $request->session()->now('_old_input', $this->form->fields($draft));
        }

        return $this->form($request, $draft);
    }

    /**
     * Throw a draft away. Only while it is a draft: an issued invoice is
     * never deleted. The audit entry keeps all of it (InvoiceDrafter::discard).
     */
    public function destroy(Request $request, string $invoiceId): RedirectResponse
    {
        $draft = $this->ownDraft($request, $invoiceId);

        try {
            $this->drafter->discard($draft);
        } catch (InvoiceNotEditableException) {
            return redirect()->route('portal.invoices.show', $invoiceId)
                ->with('error', __('An issued invoice is never deleted. Correct it with a credit or debit note.'));
        }

        return redirect()->route('portal.invoices.index')->with('success', __('Draft deleted.'));
    }

    /**
     * The organization's invoice by id, which this person may change: 404
     * for one they cannot see, 403 for one they only read.
     */
    private function ownDraft(Request $request, string $invoiceId): Invoice
    {
        $organizationId = (string) $request->attributes->get(PortalTenant::ORG_ID);

        abort_if(MemberRole::readOnly($request->user()?->roleIn($organizationId)), 403, __('Your role in this organization is read-only.'));

        try {
            $invoice = $this->invoices->find($organizationId, $invoiceId);
        } catch (ModelNotFoundException) {
            abort(404);
        }

        abort_if(Gate::denies('update', $invoice), 403, __('You cannot change invoices at this branch.'));

        return $invoice;
    }

    private function form(Request $request, ?Invoice $editing = null): View|RedirectResponse
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
            // The defaults the organization's business type suggests: the
            // invoice type and payment means the form starts on, the buyer
            // fields to hand, the treatments offered first. Never a limit.
            'businessType' => $this->businessTypes->of($organization),
            'customers' => $this->catalogue->customerChoices($organizationId),
            // Whom the insurer's share of a split sale can be billed to, and
            // whether the country splits a sale at all (SplitBilling).
            'insurers' => $this->catalogue->insurerChoices($organizationId),
            'splits' => $jurisdiction->splitBilling()->unavailable() === null,
            'products' => $this->catalogue->productChoices($organizationId),
            // The common charges: the platform's, the organization's and
            // this person's own.
            'chargePresets' => $this->chargePresets->for($organizationId, $request->user()?->getAuthIdentifier()),
            // What the form starts on: this person's own choice, else the
            // organization's or the platform's.
            'pricesIncludeVat' => (bool) $this->customizations->get('invoices.prices_include_vat', Scope::member($organizationId, (string) $request->user()?->getAuthIdentifier())),
            // The draft being edited, or null for a new invoice, and the
            // token that makes saving the same form twice one draft.
            'editing' => $editing,
            // What saved product prices are in: a product picked on an
            // invoice in another currency is converted at its rate.
            'catalogueCurrency' => $jurisdiction->currency(),
            'formToken' => $this->form->token($request->old(DraftForm::TOKEN)),
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

        // The draft being edited, or the one this same form already made:
        // saving again returns that draft rather than a copy of it.
        $replacing = $this->form->replaces($request) === null ? null : $this->ownDraft($request, (string) $this->form->replaces($request));
        $saved = $replacing === null ? $this->form->alreadySaved($request, (string) $orgId) : null;

        if ($saved !== null) {
            return redirect()->route('portal.invoices.show', $saved->id)
                ->with('success', __('This form was already saved, so nothing new was made. Here is the draft.'));
        }

        // An organization whose country's form posts elsewhere was sent back
        // to it by CreatePortalInvoiceRequest, before anything was checked.
        // As the country's rules map the request, as the API drafts it.
        try {
            $invoice = $this->drafter->draft(
                [...$request->draft(), 'idempotency_key' => $this->form->sentToken($request)],
                (string) $orgId,
                $branchId,
                countryFields: $request->countryFields(),
                replacing: $replacing,
            );
        } catch (InvoiceNotEditableException) {
            return redirect()->route('portal.invoices.show', (string) $replacing?->id)
                ->with('error', __('Only a draft can be changed. An issued invoice is corrected with a credit or debit note.'));
        } catch (\Throwable $e) {
            // Sent twice at once: the other request made it.
            $saved = $replacing === null ? $this->form->alreadySaved($request, (string) $orgId) : null;

            if ($saved !== null) {
                return redirect()->route('portal.invoices.show', $saved->id)
                    ->with('success', __('This form was already saved, so nothing new was made. Here is the draft.'));
            }

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
                ? __('Draft saved. It is numbered when it is issued.')
                : __('Invoice :number saved as a draft. It has no counter and no hash until it is issued.', ['number' => $invoice->invoice_number]));
    }
}
