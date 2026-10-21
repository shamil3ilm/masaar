<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Http\Controllers;

use App\Domains\Invoice\Services\NoteIssuing;
use App\Domains\Organization\Http\Middleware\PortalTenant;
use App\Domains\Organization\Services\TenantResolver;
use App\Domains\Platform\Approvals\OrganizationApprovals;
use App\Domains\Platform\Customization\Customizations;
use App\Domains\Platform\Customization\Scope;
use App\Domains\Platform\Models\ApprovalRequest;
use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The portal's approvals: notes waiting for a second person, the ones a
 * person asked for, and what became of the rest. Approving issues the note
 * as its maker asked; rejecting keeps it a draft, with the reason.
 */
class PortalNoteApprovalController extends Controller
{
    public function __construct(
        private readonly OrganizationApprovals $approvals,
        private readonly TenantResolver $tenant,
        private readonly Customizations $customizations,
    ) {}

    public function index(Request $request): View
    {
        $organizationId = $this->organizationId($request);

        return view('portal.approvals', [
            ...$this->approvals->lists($organizationId, $request->user()),
            // Said on the page: with nobody else to approve, a note goes
            // through, on the record.
            'hasOtherApprover' => $this->approvals->hasOtherApprover($this->tenant->getOrganization(), NoteIssuing::ACTION, $request->user()),
            'policyOn' => (bool) $this->customizations->get(NoteIssuing::POLICY, Scope::organization($organizationId)),
        ]);
    }

    public function approve(Request $request, string $requestId): RedirectResponse
    {
        try {
            $approval = $this->approvals->approve($this->organizationId($request), $requestId, $request->user());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with(
            $approval->status === ApprovalRequest::EXECUTED ? 'success' : 'error',
            $approval->status === ApprovalRequest::EXECUTED
                ? __('Approved. :outcome', ['outcome' => $approval->outcome])
                : __('Approved, and then refused when it ran: :outcome', ['outcome' => $approval->outcome]),
        );
    }

    public function reject(Request $request, string $requestId): RedirectResponse
    {
        $reason = (string) $request->validate(['reason' => ['required', 'string', 'max:2000']])['reason'];

        try {
            $this->approvals->reject($this->organizationId($request), $requestId, $request->user(), $reason);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('Rejected. The note stays a draft.'));
    }

    public function withdraw(Request $request, string $requestId): RedirectResponse
    {
        try {
            $this->approvals->withdraw($this->organizationId($request), $requestId, $request->user());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('Withdrawn. The note stays a draft.'));
    }

    private function organizationId(Request $request): string
    {
        return (string) $request->attributes->get(PortalTenant::ORG_ID);
    }
}
