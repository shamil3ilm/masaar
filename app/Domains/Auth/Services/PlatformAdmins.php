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
 * Nobody changes their own role or revokes themselves, and the platform keeps
 * at least one owner: without one, nobody could manage admins again short of
 * the server's command line.
 */
class PlatformAdmins
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly EnrolmentLinks $links,
    ) {}

    /**
     * @return Collection<int, User>
     */
    public function all(): Collection
    {
        return User::query()->where('is_platform_admin', true)->orderBy('name')->get();
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
    public function grant(string $email, PlatformRole $role, User $actor): ?string
    {
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
            $user->forceFill(['is_platform_admin' => true, 'platform_role' => $role->value])->save();

            $this->audit->logSecurity('platform_admin.granted', 'User', (string) $user->id, ['role' => $role->value], userId: (string) $actor->id);
        });

        return $user->hasTwoFactor() ? null : $this->links->issue($user, (string) $actor->id);
    }

    /**
     * @throws DomainException
     */
    public function changeRole(string $userId, PlatformRole $role, User $actor): void
    {
        DB::transaction(function () use ($userId, $role, $actor): void {
            $user = $this->admin($userId, $actor);

            if ($user->platformRole() === PlatformRole::Owner && $role !== PlatformRole::Owner) {
                $this->keepAnOwner($user);
            }

            $user->forceFill(['platform_role' => $role->value])->save();

            $this->audit->logSecurity('platform_admin.role_changed', 'User', (string) $user->id, ['role' => $role->value], userId: (string) $actor->id);
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
                $this->keepAnOwner($user);
            }

            $user->forceFill(['is_platform_admin' => false, 'platform_role' => null])->save();

            $this->audit->logSecurity('platform_admin.revoked', 'User', (string) $user->id, userId: (string) $actor->id);
        });
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

    /**
     * Owners are those with the owner role, or no role recorded (counted as
     * owners, see User::platformRole()).
     */
    private function keepAnOwner(User $leaving): void
    {
        $others = User::query()
            ->where('is_platform_admin', true)
            ->where('status', 'active')
            ->whereKeyNot($leaving->getKey())
            ->where(fn ($query) => $query->where('platform_role', PlatformRole::Owner->value)->orWhereNull('platform_role'))
            ->lockForUpdate()
            ->count();

        if ($others === 0) {
            throw new DomainException('The platform keeps at least one owner. Make someone else an owner first.');
        }
    }
}
