<?php

declare(strict_types=1);

namespace App\Domains\Organization\Services;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Auth\DTOs\RegistrationData;
use App\Domains\Auth\Models\User;
use App\Domains\Auth\Services\AccountRegistrar;
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
     * @return Collection<int, object{id: string, name: string, email: string, role: string}>
     */
    public function members(string $organizationId): Collection
    {
        return DB::table('organization_user')
            ->join('users', 'users.id', '=', 'organization_user.user_id')
            ->where('organization_user.org_id', $organizationId)
            ->where('organization_user.status', 'active')
            ->orderBy('users.name')
            ->get(['users.id', 'users.name', 'users.email', 'organization_user.role']);
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

        if ($this->members((string) $organization->id)->contains(fn (object $member): bool => Str::lower($member->email) === $email)) {
            throw new DomainException($email.' is already a member.');
        }

        $token = Str::random(48);

        $invitation = DB::transaction(function () use ($organization, $email, $role, $inviter, $token): OrganizationInvitation {
            OrganizationInvitation::withoutTenantScope(fn () => OrganizationInvitation::query()
                ->where('org_id', $organization->id)
                ->where('email', $email)
                ->whereNull('accepted_at')
                ->delete());

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

    public function revoke(string $organizationId, string $invitationId): void
    {
        OrganizationInvitation::withoutTenantScope(fn () => OrganizationInvitation::query()
            ->where('org_id', $organizationId)
            ->whereKey($invitationId)
            ->whereNull('accepted_at')
            ->delete());
    }

    /**
     * End someone's membership.
     *
     * @throws DomainException for oneself, or the last admin
     */
    public function remove(string $organizationId, string $userId, User $actor): void
    {
        if ($userId === (string) $actor->id) {
            throw new DomainException('You cannot remove yourself. Another admin can.');
        }

        DB::transaction(function () use ($organizationId, $userId, $actor): void {
            $members = DB::table('organization_user')
                ->where('org_id', $organizationId)
                ->where('status', 'active')
                ->lockForUpdate()
                ->get(['user_id', 'role']);

            $target = $members->firstWhere('user_id', $userId);

            if ($target === null) {
                throw new DomainException('That person is not a member.');
            }

            if ($target->role === 'admin' && $members->where('role', 'admin')->count() <= 1) {
                throw new DomainException('An organization keeps at least one admin.');
            }

            DB::table('organization_user')
                ->where('org_id', $organizationId)
                ->where('user_id', $userId)
                ->update(['status' => 'removed', 'updated_at' => now()]);

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
            throw new DomainException('This invitation was sent to '.$invitation->email.'. Sign in as that address to accept it.');
        }

        DB::transaction(function () use ($invitation, $user): void {
            $spent = OrganizationInvitation::withoutTenantScope(fn () => OrganizationInvitation::query()
                ->whereKey($invitation->id)
                ->whereNull('accepted_at')
                ->update(['accepted_at' => now()]));

            if ($spent !== 1) {
                throw new DomainException('This invitation has already been used.');
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
}
