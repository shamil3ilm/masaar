<?php

declare(strict_types=1);

namespace App\Domains\Auth\Services;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Auth\Enums\PlatformRole;
use App\Domains\Auth\Models\User;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Who is a platform admin, in which role - managed by owners.
 *
 * Nobody changes their own role or revokes themselves. The platform has
 * exactly one owner: nobody is made owner here, and the owner is neither
 * demoted nor revoked here - ownership moves only by transfer
 * (PlatformOwnership).
 */
class PlatformAdmins
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly EnrolmentLinks $links,
        private readonly AdminRoles $roles,
        private readonly PlatformOwnership $ownership,
    ) {}

    /**
     * @return Collection<int, User>
     */
    public function all(): Collection
    {
        return User::query()->where('is_platform_admin', true)->orderBy('name')->get();
    }

    /**
     * When each admin last signed in, by id: the latest successful sign-in
     * on the security record.
     *
     * @param  list<string>  $userIds
     * @return array<string, string>
     */
    public function lastSignIns(array $userIds): array
    {
        return DB::table('audit_logs')
            ->where('action', 'security.login.succeeded')
            ->whereIn('user_id', $userIds)
            ->groupBy('user_id')
            ->selectRaw('user_id, max(created_at) as signed_in_at')
            ->pluck('signed_in_at', 'user_id')
            ->all();
    }

    /**
     * Make an existing account an admin. It cannot sign in to the console
     * until it enrols a second factor, so the enrolment link comes back to
     * be sent to them.
     *
     * @return string|null The enrolment link, or null when they already have two-factor
     *
     * @throws DomainException
     */
    public function grant(string $email, string $role, User $actor): ?string
    {
        $this->assertRole($role);
        $this->ownership->refuseOwnerRole($role);

        $user = User::query()->where('email', $email)->first();

        if ($user === null || ! $user->isActive()) {
            throw new DomainException('No active account has that address. They sign up, or are invited to a company, first.');
        }

        // Verified, so the address is the person's: an account someone else
        // registered for it in advance does not become an admin.
        if (! $user->hasVerifiedEmail()) {
            throw new DomainException($email.' has not verified its email address yet.');
        }

        if ($user->is_platform_admin) {
            throw new DomainException($email.' is already an admin. Change their role instead.');
        }

        DB::transaction(function () use ($user, $role, $actor): void {
            $user->forceFill(['is_platform_admin' => true, 'platform_role' => $role])->save();

            $this->audit->logSecurity('platform_admin.granted', 'User', (string) $user->id, ['role' => $role], userId: (string) $actor->id);
        });

        return $user->hasTwoFactor() ? null : $this->links->issue($user, (string) $actor->id);
    }

    /**
     * @throws DomainException
     */
    public function changeRole(string $userId, string $role, User $actor): void
    {
        $this->assertRole($role);
        $this->ownership->refuseOwnerRole($role);

        DB::transaction(function () use ($userId, $role, $actor): void {
            $user = $this->admin($userId, $actor);

            if ($user->platformRole() === PlatformRole::Owner) {
                throw new DomainException('The owner steps down only by handing ownership to another admin.');
            }

            $user->forceFill(['platform_role' => $role])->save();

            $this->audit->logSecurity('platform_admin.role_changed', 'User', (string) $user->id, ['role' => $role], userId: (string) $actor->id);
        });
    }

    /**
     * @throws DomainException
     */
    public function revoke(string $userId, User $actor): void
    {
        DB::transaction(function () use ($userId, $actor): void {
            $user = $this->admin($userId, $actor);

            if ($user->platformRole() === PlatformRole::Owner) {
                throw new DomainException('The owner steps down only by handing ownership to another admin.');
            }

            $user->forceFill(['is_platform_admin' => false, 'platform_role' => null])->save();

            $this->audit->logSecurity('platform_admin.revoked', 'User', (string) $user->id, userId: (string) $actor->id);
        });
    }

    /**
     * @throws DomainException for a key that names no role
     */
    private function assertRole(string $role): void
    {
        if (! $this->roles->exists($role)) {
            throw new DomainException('There is no such admin role.');
        }
    }

    private function admin(string $userId, User $actor): User
    {
        if ($userId === (string) $actor->id) {
            throw new DomainException('Another owner has to change your own access.');
        }

        $user = User::query()->whereKey($userId)->where('is_platform_admin', true)->lockForUpdate()->first();

        if ($user === null) {
            throw new DomainException('That account is not an admin.');
        }

        return $user;
    }
}
