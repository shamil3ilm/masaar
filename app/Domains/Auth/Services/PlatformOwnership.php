<?php

declare(strict_types=1);

namespace App\Domains\Auth\Services;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Auth\Enums\AdminPermission;
use App\Domains\Auth\Enums\PlatformRole;
use App\Domains\Auth\Models\OwnerTransfer;
use App\Domains\Auth\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * The platform has exactly one owner, and ownership moves only one way.
 *
 * The owner offers it to one admin, naming the role they step down to; the
 * admin accepts; then, in one transaction, the admin becomes owner and the
 * old owner takes the role they named. Nothing else makes an owner: not a
 * role change, a custom role, an invitation, or the server's command once
 * an owner exists (PlatformAdmins, AdminRoles, AdminInvitations,
 * masaar:create-admin all refuse it).
 *
 * Customer organizations are not this: their own owners and admins are
 * MemberRole's, as many as they like.
 */
class PlatformOwnership
{
    public const OWNER = PlatformRole::Owner->value;

    /** The role an owner steps down to unless they choose another. */
    public const DEFAULT_DEMOTION = 'operations';

    public function __construct(
        private readonly AuditService $audit,
        private readonly AdminRoles $roles,
    ) {}

    /** The owner: the admin with the owner role, or no role recorded. */
    public function owner(): ?User
    {
        return User::query()
            ->where('is_platform_admin', true)
            ->where(fn ($query) => $query->where('platform_role', self::OWNER)->orWhereNull('platform_role'))
            ->orderByRaw("case when status = 'active' then 0 else 1 end")
            ->orderBy('created_at')
            ->first();
    }

    public function exists(): bool
    {
        return $this->owner() !== null;
    }

    /**
     * @throws DomainException for the owner role, which is never granted
     */
    public function refuseOwnerRole(string $role): void
    {
        if ($role === self::OWNER) {
            throw new DomainException('The platform has one owner. Ownership moves only by transfer, from the Admins page.');
        }
    }

    /** The transfer waiting, if any - there is at most one. */
    public function pending(): ?OwnerTransfer
    {
        return OwnerTransfer::query()->where('status', OwnerTransfer::PENDING)->where('expires_at', '>', now())->with(['from', 'to'])->latest()->first();
    }

    /**
     * Offer ownership to another admin. A new offer replaces one waiting.
     *
     * @throws DomainException
     */
    public function offer(User $owner, string $recipientId, string $demoteTo): OwnerTransfer
    {
        if ($owner->adminRole() !== self::OWNER) {
            throw new DomainException('Only the owner can hand ownership on.');
        }

        if ($demoteTo === self::OWNER || ! $this->roles->exists($demoteTo)) {
            throw new DomainException('Choose the role you step down to.');
        }

        $recipient = User::query()->find($recipientId);

        if ($recipient === null || $recipient->id === $owner->id || ! $recipient->isPlatformAdmin() || ! $recipient->hasTwoFactor()
            || ! $recipient->canAdmin(AdminPermission::ApprovalsDecide)) {
            throw new DomainException('Ownership goes to another active admin with two-factor sign-in who already decides approvals.');
        }

        return DB::transaction(function () use ($owner, $recipient, $demoteTo): OwnerTransfer {
            OwnerTransfer::query()->where('status', OwnerTransfer::PENDING)->lockForUpdate()->get()
                ->each(fn (OwnerTransfer $old) => $old->forceFill(['status' => OwnerTransfer::CANCELLED, 'cancelled_at' => now(), 'cancelled_by' => $owner->id])->save());

            $transfer = new OwnerTransfer(['demote_to' => $demoteTo, 'expires_at' => now()->addHours(OwnerTransfer::VALID_HOURS)]);
            $transfer->forceFill([
                'from_user_id' => $owner->id,
                'to_user_id' => $recipient->id,
                'status' => OwnerTransfer::PENDING,
                'created_by' => $owner->id,
            ])->save();

            $this->audit->logSecurity('platform_owner.offered', 'User', (string) $recipient->id, [
                'from' => (string) $owner->id,
                'demote_to' => $demoteTo,
            ], userId: (string) $owner->id);

            return $transfer;
        });
    }

    /**
     * The recipient takes ownership; the old owner steps down to the role
     * they named.
     *
     * @throws DomainException
     */
    public function accept(string $transferId, User $recipient): void
    {
        DB::transaction(function () use ($transferId, $recipient): void {
            $transfer = OwnerTransfer::query()->whereKey($transferId)->lockForUpdate()->first();

            if ($transfer === null || ! $transfer->isPending()) {
                throw new DomainException('This offer of ownership is no longer open.');
            }

            if ($transfer->to_user_id !== $recipient->id || ! $recipient->isPlatformAdmin()) {
                throw new DomainException('This offer of ownership is for another admin.');
            }

            $owner = User::query()->whereKey($transfer->from_user_id)->lockForUpdate()->first();

            if ($owner === null || $owner->adminRole() !== self::OWNER) {
                throw new DomainException('The admin who offered it is no longer the owner.');
            }

            $owner->forceFill(['platform_role' => $transfer->demote_to])->save();
            User::query()->whereKey($recipient->id)->update(['platform_role' => self::OWNER]);

            $transfer->forceFill(['status' => OwnerTransfer::ACCEPTED, 'accepted_at' => now(), 'accepted_by' => $recipient->id])->save();

            $this->audit->logSecurity('platform_owner.transferred', 'User', (string) $recipient->id, [
                'from' => (string) $owner->id,
                'to' => (string) $recipient->id,
                'previous_owner_now' => $transfer->demote_to,
                'transfer' => (string) $transfer->id,
            ], userId: (string) $recipient->id);
        });
    }

    /**
     * @throws DomainException
     */
    public function cancel(string $transferId, User $owner): void
    {
        $transfer = OwnerTransfer::query()->whereKey($transferId)->first();

        if ($transfer === null || $transfer->status !== OwnerTransfer::PENDING || $transfer->from_user_id !== $owner->id) {
            throw new DomainException('There is no offer of yours to withdraw.');
        }

        $transfer->forceFill(['status' => OwnerTransfer::CANCELLED, 'cancelled_at' => now(), 'cancelled_by' => $owner->id])->save();

        $this->audit->logSecurity('platform_owner.offer_withdrawn', 'User', (string) $transfer->to_user_id, [], userId: (string) $owner->id);
    }
}
