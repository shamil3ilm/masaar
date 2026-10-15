<?php

declare(strict_types=1);

namespace App\Domains\Organization\Services;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Auth\Models\User;
use App\Domains\Organization\DTOs\OrganizationChangesData;
use App\Domains\Organization\DTOs\OrganizationData;
use App\Domains\Organization\Models\Organization;
use Illuminate\Support\Facades\DB;

/**
 * Registers organizations and records changes to them.
 *
 * Each operation writes the organization and its audit entry together, so a
 * failure part-way leaves neither behind.
 */
class Registrar
{
    public function __construct(
        private readonly AuditService $audit,
    ) {}

    /**
     * Found an active organization with its founder as the first admin.
     *
     * An organization with no admin could never be managed, so it does not
     * exist without that membership.
     */
    public function register(User $founder, OrganizationData $data): Organization
    {
        return DB::transaction(function () use ($founder, $data): Organization {
            $organization = Organization::create([
                'name' => $data->name,
                'name_ar' => $data->nameAr,
                'country' => $data->country,
                'status' => Organization::STATUS_ACTIVE,
                'compliance_profile' => [
                    'vat_number' => $data->vatNumber,
                ],
                ...($data->address?->given() ?? []),
            ]);

            $founder->organizations()->attach($organization->id, [
                'role' => 'admin',
                'status' => 'active',
            ]);

            $this->audit->logCreated($organization);

            return $organization;
        });
    }

    /**
     * Rename an organization or change its VAT number.
     *
     * The row is re-read under lock because the VAT number is merged into the
     * current compliance profile: a concurrent change to that profile must not
     * be overwritten by a value read before it.
     */
    /**
     * Record that the organization completed ZATCA onboarding.
     *
     * Locked and re-read for the same reason amend() is, and it is the same
     * column: compliance_profile holds several unrelated keys, so a read,
     * merge and write built from a copy loaded earlier discards whatever
     * else was written in between. The onboarding controller did precisely
     * that, and amend() taking a lock does not help a path that never asks
     * for one - a VAT number changed mid-onboarding was lost without a trace.
     *
     * Idempotent, because the step it finishes is retried: the certificate is
     * a file and this flag is a row, and when only the row failed the way
     * back is to run the step again.
     */
    public function markOnboarded(Organization $organization): Organization
    {
        return DB::transaction(function () use ($organization): Organization {
            $locked = Organization::query()
                ->whereKey($organization->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $oldValues = $locked->toArray();

            $locked->update([
                'compliance_profile' => array_merge($locked->compliance_profile ?? [], [
                    'zatca_onboarded' => true,
                    'onboarded_at' => now()->toISOString(),
                ]),
            ]);

            $this->audit->logUpdated($locked, $oldValues);

            return $locked;
        });
    }

    public function amend(Organization $organization, OrganizationChangesData $changes): Organization
    {
        return DB::transaction(function () use ($organization, $changes): Organization {
            $locked = Organization::query()
                ->whereKey($organization->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $oldValues = $locked->toArray();

            $locked->update([
                'name' => $changes->name ?? $locked->name,
                'name_ar' => $changes->nameAr ?? $locked->name_ar,
                'prices_include_vat' => $changes->pricesIncludeVat ?? $locked->prices_include_vat,
                ...($changes->address?->given() ?? []),
                'compliance_profile' => array_merge(
                    $locked->compliance_profile ?? [],
                    array_filter(['vat_number' => $changes->vatNumber])
                ),
            ]);

            $this->audit->logUpdated($locked, $oldValues);

            return $locked;
        });
    }
}
