<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Http\Controllers;

use App\Domains\Compliance\Engine\DTOs\IssueOptions;
use App\Domains\Compliance\Engine\Services\ComplianceEngine;
use App\Domains\Invoice\Http\Requests\CreatePortalSplitRequest;
use App\Domains\Invoice\Models\InsuranceSplit;
use App\Domains\Invoice\Services\InsuranceSplits;
use App\Domains\Invoice\Services\InsurerReceivables;
use App\Domains\Invoice\Support\AdjustmentReason;
use App\Domains\Organization\Http\Middleware\PortalTenant;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Services\BranchAccess;
use App\Domains\Organization\Services\BranchScope;
use App\Domains\Organization\Services\TenantResolver;
use App\Domains\Platform\Access\Access;
use App\Domains\Platform\Access\Feature;
use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * A sale an insurer pays for, in a browser: saved from the invoice form as
 * a draft to its recipient - the patient, with the insurer's share for its
 * account, or the insurer that contracted for the care (InsuranceSplits) -
 * issued from its page like any draft, and the insurer's decision recorded
 * there. The report of what insurers owe is here too.
 *
 * The organization comes from PortalTenant, never from the form.
 */
class PortalInsuranceSplitController extends Controller
{
    public function __construct(
        private readonly TenantResolver $tenant,
        private readonly InsuranceSplits $splits,
        private readonly InsurerReceivables $receivables,
        private readonly BranchAccess $branchAccess,
        private readonly BranchScope $branchScope,
        private readonly ComplianceEngine $engine,
        private readonly Access $access,
    ) {}

    public function store(CreatePortalSplitRequest $request): RedirectResponse
    {
        $organizationId = (string) $request->attributes->get(PortalTenant::ORG_ID);
        $organization = $this->tenant->getOrganization() ?? abort(404);

        try {
            $branchId = $this->branchAccess->draftBranch((string) $request->user()?->getAuthIdentifier(), $organizationId, $this->branchScope->selected());
        } catch (DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        $split = $this->splits->draft($organization, $request->draft(), (array) $request->validated('split'), $branchId);

        return redirect()
            ->route('portal.invoices.show', $split->sale()?->id)
            ->with('success', __('Saved as a draft to the recipient, with the insurer\'s share beside it. Issue it when it is right.'));
    }

    /**
     * The insurer's decision: what it pays of its share of an invoice to
     * the patient, or the value it accepts of an invoice to it, and why.
     */
    public function settle(Request $request, string $splitId): RedirectResponse
    {
        [$split, $organization] = $this->split($request, $splitId);
        $validated = $request->validate([
            'approved' => ['required', 'numeric', 'min:0', 'decimal:0,2'],
            'decision' => ['nullable', Rule::in([InsuranceSplit::NOT_COVERED, InsuranceSplit::PRICE])],
            'adjustment_reason' => ['nullable', 'string', Rule::in(AdjustmentReason::values())],
        ]);
        $back = redirect()->route('portal.invoices.show', $split->sale()?->id);
        $decision = $this->access->decide($organization, $request->user(), Feature::CreditNotes);

        if (! $decision->allowed) {
            return $back->with('error', __('The decision was not recorded: :reason', ['reason' => $decision->reason]));
        }

        $outcomes = $this->splits->settle($split, $organization, (string) $validated['approved'], $validated['decision'] ?? null, $validated['adjustment_reason'] ?? null, new IssueOptions(
            file: $this->engine->jurisdictionFor($organization)->transmission()->readyToFile($organization),
        ));

        if ($outcomes['credit'] === null) {
            return $back->with('success', $split->fresh()->decision === null
                ? __('Recorded: the insurer approved its share in full.')
                : __('Recorded: the patient owes what the insurer did not pay, with its VAT. The invoice stands as issued.'));
        }

        $failed = collect([$outcomes['credit'], $outcomes['rebill']])->filter()->first(fn ($outcome) => ! $outcome->issued || $outcome->errors !== []);

        if ($failed !== null) {
            return $back->with('warning', __('Recorded, and a document was not issued or filed cleanly: :error', ['error' => $failed->errors[0] ?? __('see the split on this page.')]));
        }

        return $back->with('success', $outcomes['rebill'] === null
            ? __('Recorded: the value the insurer did not accept is credited on its invoice.')
            : __('Recorded: the care the insurer did not cover is credited on its invoice and billed to the patient.'));
    }

    /** What insurers owe, by insurer. */
    public function receivables(Request $request): View
    {
        $organizationId = (string) $request->attributes->get(PortalTenant::ORG_ID);

        return view('portal.insurer-receivables', [
            'organization' => $this->tenant->getOrganization(),
            'rows' => $this->receivables->of($organizationId),
        ]);
    }

    /**
     * The organization's split, and the organization; refused for someone
     * who does not issue at its branch.
     *
     * @return array{0: InsuranceSplit, 1: Organization}
     */
    private function split(Request $request, string $splitId): array
    {
        $organizationId = (string) $request->attributes->get(PortalTenant::ORG_ID);
        $split = $this->splits->find($organizationId, $splitId) ?? abort(404);

        foreach ($split->invoices as $invoice) {
            abort_if(Gate::denies('issue', $invoice), 403, __('You cannot issue invoices at this branch.'));
        }

        return [$split, $this->tenant->getOrganization() ?? abort(404)];
    }
}
