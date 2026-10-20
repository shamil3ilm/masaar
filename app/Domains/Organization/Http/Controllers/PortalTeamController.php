<?php

declare(strict_types=1);

namespace App\Domains\Organization\Http\Controllers;

use App\Domains\Organization\Enums\MemberRole;
use App\Domains\Organization\Http\Concerns\ResolvesPortalOrganization;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Models\OrganizationInvitation;
use App\Domains\Organization\Services\Team;
use App\Domains\Organization\Services\TenantResolver;
use App\Domains\Platform\Access\Access;
use App\Domains\Platform\Access\Feature;
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
        private readonly Access $access,
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

        if ($refused = $this->refusedToAdd($request, $organization)) {
            return $refused;
        }

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
        $organization = $this->organization($request);

        if ($refused = $this->refusedToAdd($request, $organization)) {
            return $refused;
        }

        try {
            $this->team->restore((string) $organization->id, $userId, $request->user());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('Back in the organization, in the role they had.'));
    }

    /**
     * Adding someone - inviting them, or bringing them back - is a feature
     * Access answers for: an account frozen for an overdue payment adds
     * nobody new, and says why. Who may manage people is this page's own
     * check, as it always was.
     */
    private function refusedToAdd(Request $request, Organization $organization): ?RedirectResponse
    {
        $decision = $this->access->decide($organization, $request->user(), Feature::AddUsers);

        return $decision->code === 'account_frozen' ? back()->withInput()->with('error', $decision->reason) : null;
    }

    private function organization(Request $request): Organization
    {
        return $this->portalOrganization($this->tenant, $request, __('Only an administrator of this organization can manage its people.'));
    }
}
