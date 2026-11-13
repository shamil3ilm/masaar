<?php

declare(strict_types=1);

namespace App\Domains\Organization\Http\Controllers;

use App\Domains\Organization\Http\Concerns\ResolvesPortalOrganization;
use App\Domains\Organization\Http\Requests\ChooseWorkingBranchRequest;
use App\Domains\Organization\Services\WorkingBranches;
use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\RedirectResponse;

/**
 * The branch switcher in the portal's header: one branch, or all of them.
 *
 * Kept per person and per organization (WorkingBranches), so it holds from
 * one visit to the next, and scopes the dashboard, the lists, the reports
 * and the day's close, and is the branch a new invoice is issued from.
 * A person's own preference: a viewer switches too.
 */
class PortalWorkingBranchController extends Controller
{
    use ResolvesPortalOrganization;

    public function __construct(
        private readonly WorkingBranches $working,
    ) {}

    public function choose(ChooseWorkingBranchRequest $request): RedirectResponse
    {
        $organizationId = $this->portalOrganizationId($request);
        $branch = $request->validated('branch');

        try {
            $this->working->choose((string) $request->user()?->getAuthIdentifier(), $organizationId, $branch);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $branch === null ? __('Showing every branch.') : __('Showing one branch. New invoices are issued from it.'));
    }
}
