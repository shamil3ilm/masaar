<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Auth\Services\AdminRoles;
use App\Domains\Auth\Services\PlatformOwnership;
use App\Domains\Platform\Approvals\ActionResult;
use App\Domains\Platform\Approvals\ApprovalCatalogue;
use App\Domains\Platform\Approvals\ApprovalPolicies;
use App\Domains\Platform\Approvals\Approvals;
use App\Domains\Platform\Http\Concerns\HoldsForApproval;
use App\Domains\Platform\Http\Middleware\AuditAdminAction;
use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Admin › Settings › Approval policies: which actions wait for approval, how
 * many approvers they need, which roles may approve and how long a request
 * stays open. Read by everyone in the console; changed with
 * platform.configure - and, once any policy is on, only under approval.
 */
class AdminApprovalPolicyController extends Controller
{
    use HoldsForApproval;

    public function __construct(
        private readonly ApprovalPolicies $policies,
        private readonly ApprovalCatalogue $catalogue,
        private readonly Approvals $approvals,
        private readonly AdminRoles $roles,
    ) {}

    public function index(): View
    {
        return view('admin.approvals.policies', [
            'policies' => $this->policies->all(),
            'catalogue' => $this->catalogue,
            'roles' => $this->policies->roleKeys(),
            'roleNames' => array_map(fn (array $role): string => $role['name'], $this->roles->all()),
            'maxApprovals' => ApprovalPolicies::MAX_APPROVALS,
            'maxHours' => ApprovalPolicies::MAX_HOURS,
            'allowsBypass' => $this->policies->allowsBypass(),
        ]);
    }

    /**
     * Whether a held action may go through when no other admin could ever
     * approve it. The owner's alone; under approval once someone can.
     */
    public function bypass(Request $request): RedirectResponse
    {
        abort_unless($request->user()->adminRole() === PlatformOwnership::OWNER, 403, __('Only the platform owner can change whether approvals may be bypassed.'));

        $values = $this->policies->clean(ApprovalPolicies::BYPASS, $request->only(['enabled']));

        $request->attributes->set(AuditAdminAction::TARGET, ['ApprovalPolicy', null]);
        $request->attributes->set(AuditAdminAction::CHANGES, [['enabled' => $this->policies->allowsBypass()], ['enabled' => $values['enabled']]]);

        try {
            return $this->holdOrRun($this->approvals, $request, ApprovalPolicies::POLICIES, ['action' => ApprovalPolicies::BYPASS, 'values' => $values],
                fn (ActionResult $done): RedirectResponse => redirect()->route('admin.approval-policies.index')->with('success', $done->message));
        } catch (DomainException $e) {
            return redirect()->route('admin.approval-policies.index')->with('error', $e->getMessage());
        }
    }

    public function update(Request $request, string $action): RedirectResponse
    {
        abort_unless($this->catalogue->has($action), 404);

        $values = $this->policies->clean($action, $request->only(['enabled', 'approvals_required', 'approver_roles', 'expires_after_hours']));

        $request->attributes->set(AuditAdminAction::TARGET, ['ApprovalPolicy', null]);
        $request->attributes->set(AuditAdminAction::CHANGES, [$this->policies->get($action)->toArray(), $values]);

        return $this->holdOrRun($this->approvals, $request, ApprovalPolicies::POLICIES, ['action' => $action, 'values' => $values],
            fn (ActionResult $done): RedirectResponse => redirect()->route('admin.approval-policies.index')->with('success', $done->message));
    }
}
