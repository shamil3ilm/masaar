<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Auth\Enums\AdminPermission;
use App\Domains\Auth\Services\AdminInvitations;
use App\Domains\Auth\Services\AdminRoles;
use App\Domains\Auth\Services\PlatformAdmins;
use App\Domains\Auth\Services\PlatformOwnership;
use App\Domains\Platform\Approvals\ActionResult;
use App\Domains\Platform\Approvals\Approvals;
use App\Domains\Platform\Http\Concerns\HoldsForApproval;
use App\Domains\Platform\Http\Middleware\AuditAdminAction;
use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The platform's admins and their roles. Owners only (admins.manage).
 */
class AdminAdminsController extends Controller
{
    use HoldsForApproval;

    public const LINK = 'enrolment_link';

    public function __construct(
        private readonly PlatformAdmins $admins,
        private readonly Approvals $approvals,
        private readonly AdminRoles $roles,
        private readonly AdminInvitations $invitations,
        private readonly PlatformOwnership $ownership,
    ) {}

    public function index(): View
    {
        $admins = $this->admins->all();

        return view('admin.admins', [
            'admins' => $admins,
            'roles' => $this->roles->all(),
            'signIns' => $this->admins->lastSignIns($admins->pluck('id')->all()),
            'invitations' => $this->invitations->open(),
            // Nobody is given the owner role: it moves only by transfer.
            'grantable' => array_filter($this->roles->all(), fn (array $role): bool => $role['key'] !== PlatformOwnership::OWNER),
            'transfer' => $this->ownership->pending(),
            'candidates' => $admins->filter(fn ($admin): bool => $admin->adminRole() !== PlatformOwnership::OWNER && $admin->isActive()
                && $admin->hasTwoFactor() && $admin->canAdmin(AdminPermission::ApprovalsDecide))->values(),
        ]);
    }

    /**
     * Invite an address to be an admin, account or not: they accept, make
     * their account if they have none, and enrol a second factor.
     */
    public function invite(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', Rule::in($this->roles->keys())],
        ]);

        try {
            return $this->holdOrRun($this->approvals, $request, 'admins.invite', ['email' => $data['email'], 'role' => $data['role']],
                fn (ActionResult $done): RedirectResponse => back()->with('success', $done->message));
        } catch (DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    public function withdrawInvitation(Request $request, string $invitationId): RedirectResponse
    {
        $request->attributes->set(AuditAdminAction::TARGET, ['AdminInvitation', $invitationId]);

        $this->invitations->withdraw($invitationId);

        return back()->with('success', __('Invitation withdrawn.'));
    }

    public function grant(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'role' => ['required', Rule::in($this->roles->keys())],
        ]);

        try {
            return $this->holdOrRun($this->approvals, $request, 'admins.grant', ['email' => $data['email'], 'role' => $data['role']],
                fn (ActionResult $done): RedirectResponse => back()->with('success', $done->message)->with(self::LINK, $done->data['enrolment_link'] ?? null));
        } catch (DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    public function role(Request $request, string $userId): RedirectResponse
    {
        $request->attributes->set(AuditAdminAction::TARGET, ['User', $userId]);

        $data = $request->validate(['role' => ['required', Rule::in($this->roles->keys())]]);

        try {
            return $this->holdOrRun($this->approvals, $request, 'admins.role', ['user_id' => $userId, 'role' => $data['role']],
                fn (ActionResult $done): RedirectResponse => back()->with('success', $done->message));
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function revoke(Request $request, string $userId): RedirectResponse
    {
        $request->attributes->set(AuditAdminAction::TARGET, ['User', $userId]);

        try {
            return $this->holdOrRun($this->approvals, $request, 'admins.revoke', ['user_id' => $userId],
                fn (ActionResult $done): RedirectResponse => back()->with('success', $done->message));
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}
