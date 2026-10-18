<?php

declare(strict_types=1);

namespace App\Domains\Organization\Http\Controllers;

use App\Domains\Organization\Enums\MemberRole;
use App\Domains\Organization\Http\Concerns\ResolvesPortalOrganization;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Models\OrganizationInvitation;
use App\Domains\Organization\Services\Team;
use App\Domains\Organization\Services\TenantResolver;
use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The organization's people: who belongs and in which role, inviting
 * others, and bringing back someone removed.
 *
 * Its owners and administrators only, for the organization the session
 * works in. Only an owner gives or takes the owner role (Team).
 */
class PortalTeamController extends Controller
{
    use ResolvesPortalOrganization;

    public function __construct(
        private readonly TenantResolver $tenant,
        private readonly Team $team,
    ) {}

    public function index(Request $request): View
    {
        $organization = $this->organization($request);

        return view('portal.team', [
            'organization' => $organization,
            'members' => $this->team->members((string) $organization->id),
            'removed' => $this->team->removed((string) $organization->id),
            'invitations' => $this->team->openInvitations((string) $organization->id),
            'roles' => MemberRole::cases(),
            'isOwner' => $request->user()?->roleIn((string) $organization->id) === MemberRole::Owner->value,
        ]);
    }

    public function invite(Request $request): RedirectResponse
    {
        $organization = $this->organization($request);

        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', Rule::in(OrganizationInvitation::ROLES)],
        ]);

        try {
            $this->team->invite($organization, $data['email'], $data['role'], $request->user());
        } catch (DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('success', __('Invitation sent to :email.', ['email' => $data['email']]));
    }

    public function revoke(Request $request, string $invitationId): RedirectResponse
    {
        $this->team->revoke((string) $this->organization($request)->id, $invitationId);

        return back()->with('success', __('Invitation withdrawn.'));
    }

    public function role(Request $request, string $userId): RedirectResponse
    {
        $role = (string) $request->validate(['role' => ['required', Rule::enum(MemberRole::class)]])['role'];

        try {
            $this->team->changeRole((string) $this->organization($request)->id, $userId, $role, $request->user());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('Role changed.'));
    }

    public function remove(Request $request, string $userId): RedirectResponse
    {
        try {
            $this->team->remove((string) $this->organization($request)->id, $userId, $request->user());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('Removed from the organization.'));
    }

    public function restore(Request $request, string $userId): RedirectResponse
    {
        try {
            $this->team->restore((string) $this->organization($request)->id, $userId, $request->user());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('Back in the organization, in the role they had.'));
    }

    private function organization(Request $request): Organization
    {
        return $this->portalOrganization($this->tenant, $request, __('Only an administrator of this organization can manage its people.'));
    }
}
