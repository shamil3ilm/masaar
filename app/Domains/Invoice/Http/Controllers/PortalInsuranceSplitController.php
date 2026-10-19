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
 * A sale split with an insurer, in a browser: saved from the invoice form
 * as two linked drafts, both issued with one confirmation, and the
 * insurer's decision recorded on either invoice's page (InsuranceSplits).
 * The report of what insurers owe is here too.
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
            ->route('portal.invoices.show', $split->share(InsuranceSplit::PATIENT)?->id)
            ->with('success', __('Saved as two drafts: the patient\'s invoice and the insurer\'s. Issue both from either one.'));
    }

    /** Both shares, the patient's first, with one confirmation. */
    public function issue(Request $request, string $splitId): RedirectResponse
    {
        [$split, $organization] = $this->split($request, $splitId);
        $back = redirect()->route('portal.invoices.show', $split->share(InsuranceSplit::PATIENT)?->id);
        $decision = $this->access->decide($organization, $request->user(), Feature::IssueInvoices);

        if (! $decision->allowed) {
            return $back->with('error', __('The invoices were not issued: :reason', ['reason' => $decision->reason]));
        }

        $outcomes = $this->splits->issue($split, $organization, new IssueOptions(
            file: $this->engine->jurisdictionFor($organization)->transmission()->readyToFile($organization),
        ));

        foreach (['patient' => $outcomes['patient'], 'insurer' => $outcomes['insurer']] as $outcome) {
            if ($outcome === null || ! $outcome->issued) {
                return $back->with('error', $outcome?->errors[0] ?? __('The invoices could not be issued.'));
            }

            if ($outcome->errors !== []) {
                return $back->with('warning', __('Issued, and filing did not succeed: :error The invoice keeps its counter and will be retried.', ['error' => $outcome->errors[0]]));
            }
        }

        return $back->with('success', __('Both invoices are issued: the patient\'s and the insurer\'s.'));
    }

    /** The insurer's decision: what it approved of its share, with VAT. */
    public function settle(Request $request, string $splitId): RedirectResponse
    {
        [$split, $organization] = $this->split($request, $splitId);
        $validated = $request->validate([
            'approved' => ['required', 'numeric', 'min:0', 'decimal:0,2'],
            'adjustment_reason' => ['nullable', 'string', Rule::in(AdjustmentReason::values())],
        ]);
        $back = redirect()->route('portal.invoices.show', $split->share(InsuranceSplit::INSURER)?->id);
        $decision = $this->access->decide($organization, $request->user(), Feature::CreditNotes);

        if (! $decision->allowed) {
            return $back->with('error', __('The decision was not recorded: :reason', ['reason' => $decision->reason]));
        }

        $outcomes = $this->splits->settle($split, $organization, (string) $validated['approved'], $validated['adjustment_reason'] ?? null, new IssueOptions(
            file: $this->engine->jurisdictionFor($organization)->transmission()->readyToFile($organization),
        ));

        if ($outcomes['credit'] === null) {
            return $back->with('success', __('Recorded: the insurer approved its share in full.'));
        }

        $failed = collect([$outcomes['credit'], $outcomes['rebill']])->first(fn ($outcome) => $outcome === null || ! $outcome->issued || $outcome->errors !== []);

        return $failed === null
            ? $back->with('success', __('Recorded: the part the insurer declined is credited on its invoice and billed to the patient.'))
            : $back->with('warning', __('Recorded, and a document was not issued or filed cleanly: :error', ['error' => $failed?->errors[0] ?? __('see the split on this page.')]));
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
