<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Auth\Enums\PlatformRole;
use App\Domains\Platform\Approvals\ActionResult;
use App\Domains\Platform\Approvals\ApprovalCatalogue;
use App\Domains\Platform\Approvals\ApprovalPolicies;
use App\Domains\Platform\Approvals\Approvals;
use App\Domains\Platform\Http\Concerns\HoldsForApproval;
use App\Domains\Platform\Http\Middleware\AuditAdminAction;
use App\Http\Controllers\Controller;
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
    ) {}

    public function index(): View
    {
        return view('admin.approvals.policies', [
            'policies' => $this->policies->all(),
            'catalogue' => $this->catalogue,
            'roles' => $this->policies->roleKeys(),
            'roleNames' => collect(PlatformRole::cases())->mapWithKeys(fn (PlatformRole $role): array => [$role->value => $role->label()])->all(),
            'maxApprovals' => ApprovalPolicies::MAX_APPROVALS,
            'maxHours' => ApprovalPolicies::MAX_HOURS,
        ]);
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
