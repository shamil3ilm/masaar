<?php

declare(strict_types=1);

namespace App\Domains\Organization\Services;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Organization\Models\Organization;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * A platform admin suspending an organization, with a reason, and letting it
 * back in.
 *
 * Suspended, it issues nothing new and connects nothing new to its authority
 * (FilingClearance::assertCleared, Access). It is never locked out of what
 * it has: its people still sign in and read, report and export everything,
 * and the invoices it already issued keep being filed - reporting or
 * clearing an issued invoice is its obligation, and withholding that would
 * make the suspension its compliance failure.
 */
class OrganizationStatus
{
    public function __construct(
        private readonly AuditService $audit,
    ) {}

    /**
     * @throws DomainException when it is not active
     */
    public function suspend(string $organizationId, string $reason, string $actorId): Organization
    {
        return DB::transaction(function () use ($organizationId, $reason, $actorId): Organization {
            $organization = $this->locked($organizationId);

            if ($organization->status !== Organization::STATUS_ACTIVE) {
                throw new DomainException(__('Only an active organization can be suspended. This one is :status.', ['status' => $organization->status]));
            }

            $before = $organization->toArray();

            $organization->forceFill([
                'status' => Organization::STATUS_SUSPENDED,
                'suspended_at' => now(),
                'suspended_by' => $actorId,
                'suspension_reason' => mb_substr($reason, 0, 500),
            ])->save();

            $this->audit->logUpdated($organization, $before, ['reason' => $reason, 'actor' => $actorId]);

            return $organization;
        });
    }

    /**
     * @throws DomainException when it is not suspended
     */
    public function reactivate(string $organizationId, string $actorId): Organization
    {
        return DB::transaction(function () use ($organizationId, $actorId): Organization {
            $organization = $this->locked($organizationId);

            if (! $organization->isSuspended()) {
                throw new DomainException(__('This organization is not suspended.'));
            }

            $before = $organization->toArray();

            $organization->forceFill([
                'status' => Organization::STATUS_ACTIVE,
                'suspended_at' => null,
                'suspended_by' => null,
                'suspension_reason' => null,
            ])->save();

            $this->audit->logUpdated($organization, $before, ['actor' => $actorId]);

            return $organization;
        });
    }

    private function locked(string $organizationId): Organization
    {
        return Organization::query()->whereKey($organizationId)->lockForUpdate()->first()
            ?? throw new DomainException(__('There is no such organization.'));
    }
}
