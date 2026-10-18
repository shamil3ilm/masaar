<?php

declare(strict_types=1);

namespace App\Domains\Organization\Services;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Auth\DTOs\RegistrationData;
use App\Domains\Auth\Models\User;
use App\Domains\Auth\Services\AccountRegistrar;
use App\Domains\Organization\Enums\MemberRole;
use App\Domains\Organization\Mail\InvitationMail;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Models\OrganizationInvitation;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Who belongs to an organization, and how others join it.
 *
 * An admin invites by email; the person accepts through a one-time link,
 * signed in as that address or by creating an account for it. Membership is
 * ended rather than deleted, so the record of who did what stays readable.
 */
class Team
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly AccountRegistrar $accounts,
    ) {}

    /**
     * The organization's people, with when each last did something in it -
     * the latest entry they made in its audit record.
     *
     * @return Collection<int, object{id: string, name: string, email: string, role: string, last_active_at: string|null}>
     */
    public function members(string $organizationId): Collection
    {
        return $this->withActivity($organizationId, 'active');
    }

    /**
     * Those whose membership was ended, who can be brought back.
     *
     * @return Collection<int, object{id: string, name: string, email: string, role: string, last_active_at: string|null}>
     */
    public function removed(string $organizationId): Collection
    {
        return $this->withActivity($organizationId, 'removed');
    }

    /**
     * Change someone's role.
     *
     * Nobody changes their own; only an owner gives or takes the owner role;
     * and the organization keeps an owner or an admin.
     *
     * @throws DomainException
     */
    public function changeRole(string $organizationId, string $userId, string $role, User $actor): void
    {
        if ($userId === (string) $actor->id) {
            throw new DomainException(__('You cannot change your own role. Another admin can.'));
        }

        if (MemberRole::tryFrom($role) === null) {
            throw new DomainException(__('There is no such role.'));
        }

        DB::transaction(function () use ($organizationId, $userId, $role, $actor): void {
            $members = $this->lockedMembers($organizationId);
            $target = $members->firstWhere('user_id', $userId) ?? throw new DomainException(__('That person is not a member.'));

            if (($target->role === MemberRole::Owner->value || $role === MemberRole::Owner->value) && ! $this->isOwner($members, $actor)) {
                throw new DomainException(__('Only an owner can give or take the owner role.'));
            }

            if (MemberRole::administers($target->role) && ! MemberRole::administers($role)) {
                $this->keepAnAdministrator($members, $userId);
            }

            DB::table('organization_user')->where('org_id', $organizationId)->where('user_id', $userId)
                ->update(['role' => $role, 'updated_by' => (string) $actor->id, 'updated_at' => now()]);

            $this->audit->logSecurity('member.role_changed', 'Organization', $organizationId, [
                'user_id' => $userId,
                'from' => $target->role,
                'to' => $role,
            ], userId: (string) $actor->id);
        });
    }

    /**
     * Bring back someone whose membership was ended, in the role they had.
     * An owner comes back only on an owner's say.
     *
     * @throws DomainException
     */
    public function restore(string $organizationId, string $userId, User $actor): void
    {
        DB::transaction(function () use ($organizationId, $userId, $actor): void {
            $row = DB::table('organization_user')->where('org_id', $organizationId)->where('user_id', $userId)
                ->where('status', 'removed')->lockForUpdate()->first();

            if ($row === null) {
                throw new DomainException(__('That person was not removed from this organization.'));
            }

            if ($row->role === MemberRole::Owner->value && ! $this->isOwner($this->lockedMembers($organizationId), $actor)) {
                throw new DomainException(__('Only an owner can give or take the owner role.'));
            }

            DB::table('organization_user')->where('org_id', $organizationId)->where('user_id', $userId)
                ->update(['status' => 'active', 'updated_by' => (string) $actor->id, 'updated_at' => now()]);

            $this->audit->logSecurity('member.restored', 'Organization', $organizationId, [
                'user_id' => $userId,
                'role' => $row->role,
            ], userId: (string) $actor->id);
        });
    }

    /**
     * @return Collection<int, OrganizationInvitation>
     */
    public function openInvitations(string $organizationId): Collection
    {
        return OrganizationInvitation::withoutTenantScope(fn () => OrganizationInvitation::query()
            ->where('org_id', $organizationId)
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->latest()
            ->get());
    }

    /**
     * Invite an address, replacing any open invitation it already has here.
     *
     * @throws DomainException when the address is already a member
     */
    public function invite(Organization $organization, string $email, string $role, User $inviter): OrganizationInvitation
    {
        $email = Str::lower(trim($email));

        if ($role === MemberRole::Owner->value && ! $this->isOwner($this->lockedMembers((string) $organization->id), $inviter)) {
            throw new DomainException(__('Only an owner can give or take the owner role.'));
        }

        if ($this->members((string) $organization->id)->contains(fn (object $member): bool => Str::lower($member->email) === $email)) {
            throw new DomainException(__(':email is already a member.', ['email' => $email]));
        }

        $token = Str::random(48);

        $invitation = DB::transaction(function () use ($organization, $email, $role, $inviter, $token): OrganizationInvitation {
            // Each through the model, so who replaced it is kept (deleted_by).
            OrganizationInvitation::withoutTenantScope(fn () => OrganizationInvitation::query()
                ->where('org_id', $organization->id)
                ->where('email', $email)
                ->whereNull('accepted_at')
                ->get()
                ->each->delete());

            $invitation = OrganizationInvitation::withoutTenantScope(fn () => OrganizationInvitation::create([
                'org_id' => $organization->id,
                'email' => $email,
                'role' => $role,
                'token_hash' => self::hash($token),
                'invited_by' => $inviter->id,
                'expires_at' => now()->addDays(OrganizationInvitation::VALID_DAYS),
            ]));

            $this->audit->logSecurity('member.invited', 'Organization', (string) $organization->id, [
                'email' => $email,
                'role' => $role,
            ], userId: (string) $inviter->id);

            return $invitation;
        });

        Mail::to($email)->send(new InvitationMail(
            organizationName: (string) $organization->name,
            inviterName: (string) $inviter->name,
            role: $role,
            acceptUrl: route('invitations.show', $token),
            validDays: OrganizationInvitation::VALID_DAYS,
        ));

        return $invitation;
    }

    /** Through the model, so who revoked it is kept (deleted_by). */
    public function revoke(string $organizationId, string $invitationId): void
    {
        OrganizationInvitation::withoutTenantScope(fn () => OrganizationInvitation::query()
            ->where('org_id', $organizationId)
            ->whereKey($invitationId)
            ->whereNull('accepted_at')
            ->first()
            ?->delete());
    }

    /**
     * End someone's membership.
     *
     * @throws DomainException for oneself, an owner by anyone but an owner, or the last owner or admin
     */
    public function remove(string $organizationId, string $userId, User $actor): void
    {
        if ($userId === (string) $actor->id) {
            throw new DomainException(__('You cannot remove yourself. Another admin can.'));
        }

        DB::transaction(function () use ($organizationId, $userId, $actor): void {
            $members = $this->lockedMembers($organizationId);

            $target = $members->firstWhere('user_id', $userId);

            if ($target === null) {
                throw new DomainException(__('That person is not a member.'));
            }

            if ($target->role === MemberRole::Owner->value && ! $this->isOwner($members, $actor)) {
                throw new DomainException(__('Only an owner can give or take the owner role.'));
            }

            if (MemberRole::administers($target->role)) {
                $this->keepAnAdministrator($members, $userId);
            }

            DB::table('organization_user')
                ->where('org_id', $organizationId)
                ->where('user_id', $userId)
                ->update(['status' => 'removed', 'updated_by' => (string) $actor->id, 'updated_at' => now()]);

            $this->audit->logSecurity('member.removed', 'Organization', $organizationId, [
                'user_id' => $userId,
            ], userId: (string) $actor->id);
        });
    }

    /**
     * The open invitation a link names, or null.
     */
    public function invitation(string $token): ?OrganizationInvitation
    {
        $invitation = OrganizationInvitation::withoutTenantScope(fn () => OrganizationInvitation::query()
            ->where('token_hash', self::hash($token))
            ->first());

        return $invitation?->isOpen() ? $invitation : null;
    }

    /**
     * Make the user a member, with the invited role, and spend the link.
     *
     * @throws DomainException when the user is not the address invited
     */
    public function accept(OrganizationInvitation $invitation, User $user): void
    {
        if (Str::lower((string) $user->email) !== Str::lower($invitation->email)) {
            throw new DomainException(__('This invitation was sent to :email. Sign in as that address to accept it.', ['email' => $invitation->email]));
        }

        DB::transaction(function () use ($invitation, $user): void {
            $spent = OrganizationInvitation::withoutTenantScope(fn () => OrganizationInvitation::query()
                ->whereKey($invitation->id)
                ->whereNull('accepted_at')
                ->update(['accepted_at' => now()]));

            if ($spent !== 1) {
                throw new DomainException(__('This invitation has already been used.'));
            }

            $user->organizations()->syncWithoutDetaching([
                $invitation->org_id => ['role' => $invitation->role, 'status' => 'active'],
            ]);

            $this->audit->logSecurity('member.joined', 'Organization', (string) $invitation->org_id, [
                'role' => $invitation->role,
            ], userId: (string) $user->id);
        });
    }

    /**
     * Accept by creating the account the invitation is for - verified
     * already, since the link reached the address.
     */
    public function join(OrganizationInvitation $invitation, string $name, string $password): User
    {
        return DB::transaction(function () use ($invitation, $name, $password): User {
            $user = $this->accounts->register(RegistrationData::from([
                'name' => $name,
                'email' => $invitation->email,
                'password' => $password,
            ]));

            $user->forceFill([
                'email_verified_at' => now(),
                'terms_accepted_at' => now(),
                'terms_version' => (string) config('signup.terms_version'),
            ])->save();

            $this->accept($invitation, $user);

            return $user;
        });
    }

    public function hasAccount(string $email): bool
    {
        return User::query()->where('email', $email)->exists();
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * @return Collection<int, object{user_id: string, role: string}>
     */
    private function lockedMembers(string $organizationId): Collection
    {
        return DB::table('organization_user')
            ->where('org_id', $organizationId)
            ->where('status', 'active')
            ->lockForUpdate()
            ->get(['user_id', 'role']);
    }

    /**
     * @param  Collection<int, object{user_id: string, role: string}>  $members
     */
    private function isOwner(Collection $members, User $actor): bool
    {
        return $members->contains(fn (object $member): bool => $member->user_id === (string) $actor->id && $member->role === MemberRole::Owner->value);
    }

    /**
     * @param  Collection<int, object{user_id: string, role: string}>  $members
     *
     * @throws DomainException when the leaving person is the last owner or admin
     */
    private function keepAnAdministrator(Collection $members, string $leavingId): void
    {
        $others = $members->filter(fn (object $member): bool => $member->user_id !== $leavingId && MemberRole::administers($member->role));

        if ($others->isEmpty()) {
            throw new DomainException(__('An organization keeps at least one admin.'));
        }
    }

    /**
     * @return Collection<int, object{id: string, name: string, email: string, role: string, last_active_at: string|null}>
     */
    private function withActivity(string $organizationId, string $status): Collection
    {
        $people = DB::table('organization_user')
            ->join('users', 'users.id', '=', 'organization_user.user_id')
            ->where('organization_user.org_id', $organizationId)
            ->where('organization_user.status', $status)
            ->orderBy('users.name')
            ->get(['users.id', 'users.name', 'users.email', 'organization_user.role']);

        $activity = DB::table('audit_logs')
            ->where('org_id', $organizationId)
            ->whereIn('user_id', $people->pluck('id')->all())
            ->groupBy('user_id')
            ->selectRaw('user_id, max(created_at) as last_active_at')
            ->pluck('last_active_at', 'user_id');

        return $people->map(function (object $person) use ($activity): object {
            $person->last_active_at = $activity[$person->id] ?? null;

            return $person;
        });
    }
}
