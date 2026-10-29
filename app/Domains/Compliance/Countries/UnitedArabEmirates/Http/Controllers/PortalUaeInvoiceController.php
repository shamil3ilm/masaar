<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\UnitedArabEmirates\Http\Controllers;

use App\Domains\Compliance\Countries\UnitedArabEmirates\Http\Requests\PortalUaeInvoiceRequest;
use App\Domains\Compliance\Countries\UnitedArabEmirates\Presentation\BuyerFields;
use App\Domains\Compliance\Countries\UnitedArabEmirates\Reports\UaeDocuments;
use App\Domains\Compliance\Countries\UnitedArabEmirates\Support\UaeDrafts;
use App\Domains\Compliance\Countries\UnitedArabEmirates\Support\UaePortal;
use App\Domains\Invoice\Services\Catalogue;
use App\Domains\Invoice\Services\DraftForm;
use App\Domains\Organization\Services\BranchAccess;
use App\Domains\Organization\Services\BranchScope;
use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * A UAE organization's invoices, in the portal: drafting one from the UAE
 * form, and downloading the e-invoice an issued one was sent as.
 *
 * The draft is made by UaeDrafts, as the API makes it, from the same
 * request rules. Issuing is PortalInvoiceFilingController's, which sends a
 * UAE organization's invoice to InvoiceIssuer. The organization is the
 * session's, never the form's.
 */
class PortalUaeInvoiceController extends Controller
{
    public function __construct(
        private readonly UaePortal $portal,
        private readonly UaeDrafts $drafts,
        private readonly UaeDocuments $documents,
        private readonly Catalogue $catalogue,
        private readonly BranchAccess $branches,
        private readonly BranchScope $scope,
        private readonly DraftForm $form,
    ) {}

    public function store(PortalUaeInvoiceRequest $request): RedirectResponse
    {
        $organization = $this->portal->organization();

        // The branch it is issued from: the one chosen in the switcher, or a
        // cashier's own - and never one they only read at.
        try {
            $branchId = $this->branches->draftBranch((string) $request->user()?->getAuthIdentifier(), (string) $organization->id, $this->scope->selected());
        } catch (DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        // The same form sent again - Back, then save - returns the draft it made (DraftForm).
        $saved = $this->form->alreadySaved($request, (string) $organization->id);

        if ($saved !== null) {
            return redirect()->route('portal.invoices.show', $saved->id)
                ->with('success', __('This form was already saved, so nothing new was made. Here is the draft.'));
        }

        try {
            $invoice = $this->drafts->draft($request, (string) $organization->id, $branchId, $this->form->sentToken($request));
        } catch (\Throwable $e) {
            // As on the portal's other invoice form: what was typed goes back with the message,
            // rather than being lost to an error page.
            Log::error('Portal UAE invoice draft failed', [
                'org_id' => $organization->id,
                'exception' => $e->getMessage(),
            ]);

            return back()
                ->withInput()
                ->with('error', __('The invoice could not be saved. Nothing was written, and what you typed is still here. If this keeps happening, the detail is in the application log.'));
        }

        // As on the portal's other invoice form: after the draft and never at its expense.
        // The customer keeps the buyer's endpoint and emirate as well.
        if ($request->boolean('save_customer')) {
            try {
                $this->catalogue->rememberBuyer((string) $organization->id, $request->validated(), BuyerFields::kept($request->validated()));
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return redirect()
            ->route('portal.invoices.show', $invoice->id)
            ->with('success', $invoice->invoice_number === null
                ? __('Draft saved. It is numbered when it is issued and sent as a UAE e-invoice.')
                : __('Invoice :number saved as a draft. It is sent as a UAE e-invoice when it is issued.', ['number' => $invoice->invoice_number]));
    }

    /**
     * The e-invoice an issued invoice was sent as, exactly as it travelled.
     */
    public function xml(string $invoiceId): Response
    {
        $organization = $this->portal->organization();
        $document = $this->documents->outboundFor((string) $organization->id, $invoiceId) ?? abort(404);

        return response((string) $document->xml, 200, UaeDocuments::xmlHeaders($document));
    }
}
