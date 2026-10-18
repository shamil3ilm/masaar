<?php

declare(strict_types=1);

namespace App\Domains\Auth\Services;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Auth\DTOs\RegistrationData;
use App\Domains\Auth\Mail\AdminInvitationMail;
use App\Domains\Auth\Models\AdminInvitation;
use App\Domains\Auth\Models\User;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Inviting someone to be a platform admin by email, whether or not they have
 * an account yet.
 *
 * The link proves the address. Accepting it signed in as that address, or
 * by making the account it is for, gives the role; the person then enrols a
 * second factor through a one-time link before the console opens to them -
 * the same three steps granting an existing account takes, in one email.
 */
class AdminInvitations
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly AdminRoles $roles,
        private readonly AccountRegistrar $accounts,
        private readonly EnrolmentLinks $links,
    ) {}

    /**
     * @return Collection<int, AdminInvitation>
     */
    public function open(): Collection
    {
        return AdminInvitation::query()->whereNull('accepted_at')->where('expires_at', '>', now())->latest()->get();
    }

    /**
     * Invite an address, replacing any open invitation it already has.
     *
     * @throws DomainException for a role that does not exist, or an address that is already an admin
     */
    public function invite(string $email, string $role, User $inviter): AdminInvitation
    {
        $email = Str::lower(trim($email));

        if (! $this->roles->exists($role)) {
            throw new DomainException('There is no such admin role.');
        }

        if (User::query()->where('email', $email)->where('is_platform_admin', true)->exists()) {
            throw new DomainException($email.' is already an admin. Change their role instead.');
        }

        $token = Str::random(48);

        $invitation = DB::transaction(function () use ($email, $role, $inviter, $token): AdminInvitation {
            AdminInvitation::query()->where('email', $email)->whereNull('accepted_at')->delete();

            $invitation = AdminInvitation::create([
                'email' => $email,
                'role' => $role,
                'token_hash' => self::hash($token),
                'invited_by' => $inviter->id,
                'expires_at' => now()->addDays(AdminInvitation::VALID_DAYS),
            ]);

            $this->audit->logSecurity('platform_admin.invited', 'User', null, ['email' => $email, 'role' => $role], userId: (string) $inviter->id);

            return $invitation;
        });

        Mail::to($email)->send(new AdminInvitationMail(
            inviterName: (string) $inviter->name,
            role: $this->roles->name($role),
            acceptUrl: route('admin-invitations.show', $token),
            validDays: AdminInvitation::VALID_DAYS,
        ));

        return $invitation;
    }

    public function withdraw(string $invitationId): void
    {
        AdminInvitation::query()->whereKey($invitationId)->whereNull('accepted_at')->delete();
    }

    /** The open invitation a link names, or null. */
    public function find(string $token): ?AdminInvitation
    {
        $invitation = AdminInvitation::query()->where('token_hash', self::hash($token))->first();

        return $invitation?->isOpen() ? $invitation : null;
    }

    public function hasAccount(string $email): bool
    {
        return User::query()->where('email', $email)->exists();
    }

    /**
     * Make the user an admin in the invited role and spend the link.
     *
     * @return string|null the enrolment link for their second factor, or null when they have one
     *
     * @throws DomainException when the user is not the address invited
     */
    public function accept(AdminInvitation $invitation, User $user): ?string
    {
        if (Str::lower((string) $user->email) !== Str::lower($invitation->email)) {
            throw new DomainException(__('This invitation was sent to :email. Sign in as that address to accept it.', ['email' => $invitation->email]));
        }

        if (! $user->isActive()) {
            throw new DomainException(__('This account is suspended.'));
        }

        DB::transaction(function () use ($invitation, $user): void {
            $spent = AdminInvitation::query()->whereKey($invitation->id)->whereNull('accepted_at')
                ->update(['accepted_at' => now(), 'accepted_by' => $user->id]);

            if ($spent !== 1) {
                throw new DomainException(__('This invitation has already been used.'));
            }

            $user->forceFill([
                'is_platform_admin' => true,
                'platform_role' => $invitation->role,
                // The link reached the address.
                'email_verified_at' => $user->email_verified_at ?? now(),
            ])->save();

            $this->audit->logSecurity('platform_admin.granted', 'User', (string) $user->id, [
                'role' => $invitation->role,
                'invitation' => (string) $invitation->id,
            ], userId: $invitation->invited_by);
        });

        return $user->hasTwoFactor() ? null : $this->links->issue($user, $invitation->invited_by);
    }

    /**
     * Accept by making the account the invitation is for.
     *
     * @return string|null the enrolment link
     */
    public function join(AdminInvitation $invitation, string $name, string $password): ?string
    {
        return DB::transaction(function () use ($invitation, $name, $password): ?string {
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

            return $this->accept($invitation, $user);
        });
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
