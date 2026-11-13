<?php

declare(strict_types=1);

namespace App\Domains\Organization\Services;

use App\Domains\Audit\Attribution\Origin;
use App\Domains\Audit\Services\AuditService;
use App\Domains\Compliance\Engine\Services\JurisdictionResolver;
use App\Domains\Organization\Events\BranchRemoved;
use App\Domains\Organization\Exceptions\BranchInUseException;
use App\Domains\Organization\Models\Branch;
use App\Domains\Organization\Models\EgsUnit;
use App\Domains\Organization\Models\Organization;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Service for managing organization branches (EGS units).
 *
 * Handles branch CRUD operations and credential storage for multi-EGS support.
 */
class BranchService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly Origin $origin,
        private readonly JurisdictionResolver $jurisdictions,
    ) {}

    /**
     * Create a new branch for an organization.
     */
    public function create(Organization $organization, array $data): Branch
    {
        return DB::transaction(function () use ($organization, $data) {
            // The serial is numbered from the branch count and the first
            // branch becomes default, so concurrent creates would share a
            // serial or both skip the default without this lock.
            Branch::lockOrganization($organization->id);

            // The branch's id is its first till's, which the serial names
            // (Branch::generateDeviceSerial), so it is chosen first.
            $id = (new Branch)->newUniqueId();
            $deviceSerial = $data['device_serial'] ?? Branch::generateDeviceSerial($id);

            $branch = Branch::query()->forceCreate([
                'id' => $id,
                'org_id' => $organization->id,
                'name' => $data['name'],
                'name_ar' => $data['name_ar'] ?? null,
                'device_serial' => $deviceSerial,
                'industry' => $data['industry'] ?? 'General',
                'street' => $data['street'],
                'building_number' => $data['building_number'],
                'additional_number' => $data['additional_number'] ?? null,
                'cr_number' => ($data['cr_number'] ?? '') === '' ? null : $data['cr_number'],
                'district' => $data['district'],
                'city' => $data['city'],
                'postal_code' => $data['postal_code'],
                'country_code' => $data['country_code'] ?? 'SA',
                'emirate' => $data['emirate'] ?? null,
            ]);

            // Set as default if first branch
            if ($organization->branches()->count() === 1) {
                $branch->setAsDefault();
            }

            // On the organization's activity record, as each branch change is.
            $this->audit->logCreated($branch);

            return $branch;
        });
    }

    /**
     * Update branch details.
     *
     * Note: device_serial cannot be changed after onboarding.
     */
    public function update(Branch $branch, array $data): Branch
    {
        $updateData = array_filter([
            'name' => $data['name'] ?? null,
            'name_ar' => $data['name_ar'] ?? null,
            'industry' => $data['industry'] ?? null,
            'street' => $data['street'] ?? null,
            'building_number' => $data['building_number'] ?? null,
            'additional_number' => $data['additional_number'] ?? null,
            'district' => $data['district'] ?? null,
            'city' => $data['city'] ?? null,
            'postal_code' => $data['postal_code'] ?? null,
            'emirate' => $data['emirate'] ?? null,
        ], fn ($v) => $v !== null);

        // Its own commercial registration may be set or cleared, empty
        // meaning the organization's.
        if (array_key_exists('cr_number', $data)) {
            $updateData['cr_number'] = ($data['cr_number'] ?? '') === '' ? null : $data['cr_number'];
        }

        // Prevent device_serial change if already onboarded
        if ($branch->onboarding_status !== Branch::STATUS_PENDING && isset($data['device_serial'])) {
            unset($data['device_serial']);
        }

        $before = $branch->only(array_keys($updateData));
        $branch->update($updateData);

        if ($branch->wasChanged()) {
            $this->audit->logUpdated($branch, $before);
        }

        return $branch->fresh();
    }

    /**
     * Delete branch.
     *
     * Cannot delete if branch has invoices or is the only active branch.
     */
    public function delete(Branch $branch): bool
    {
        // Check for invoices
        if ($branch->invoices()->exists()) {
            throw BranchInUseException::hasInvoices();
        }

        // Handing the default to another branch and removing this one commit
        // together, so a failed delete leaves the default where it was.
        $deleted = DB::transaction(function () use ($branch): bool {
            if ($branch->is_default) {
                Branch::where('org_id', $branch->org_id)
                    ->where('id', '!=', $branch->id)
                    ->active()
                    ->first()
                    ?->setAsDefault();
            }

            // Its tills go with it. With no invoice, none of them signed
            // anything left to file, so each is retired at once - never
            // deleted, as no till is.
            foreach (EgsUnit::acrossTenants()->where('branch_id', $branch->id)->where('status', '!=', EgsUnit::RETIRED)->get() as $unit) {
                $unit->forceFill([
                    'status' => EgsUnit::RETIRED,
                    'retired_at' => now(),
                    'retired_by' => $this->origin->actorId(),
                ])->save();
            }

            return (bool) $branch->delete();
        });

        // Its device's credentials are the country module's, and are files
        // outside the transaction, so they go only once the row is gone
        // (BranchRemoved). In the other order a failed delete leaves a
        // branch that can no longer sign.
        if ($deleted) {
            BranchRemoved::dispatch((string) $branch->org_id, (string) $branch->id);
        }

        return $deleted;
    }

    /**
     * The branch an invoice belongs to when it names none.
     *
     * Returns the branch marked default, else any active branch, else creates
     * "Main Branch". ZATCA attributes every document to an EGS unit, including
     * a single-site taxpayer's, so this always returns one rather than null.
     *
     * Creation happens under the organization lock after looking again, so
     * concurrent first calls create one "Main Branch" between them. The first
     * look is unlocked because an existing default is the common case.
     *
     * The branch takes the organization's address. It used to fill a gap with
     * an invented one - "Main Street", building 0001, Central, Riyadh, postal
     * code 00000 - which is a seller address on a tax invoice nobody gave.
     * Now an incomplete address is refused, and has to be completed first.
     *
     * @throws DomainException when the organization's address is incomplete
     */
    public function getOrCreateDefault(Organization $organization): Branch
    {
        return $this->findDefault($organization)
            ?? DB::transaction(function () use ($organization): Branch {
                Branch::lockOrganization($organization->id);

                return $this->findDefault($organization) ?? $this->create($organization, [
                    'name' => 'Main Branch',
                    'name_ar' => 'الفرع الرئيسي',
                    ...$this->addressOf($organization),
                ]);
            });
    }

    /**
     * @return array<string, string|null>
     *
     * @throws DomainException
     */
    private function addressOf(Organization $organization): array
    {
        $address = [
            'street' => $organization->street,
            'building_number' => $organization->building_number,
            'district' => $organization->district,
            'city' => $organization->city,
            'postal_code' => $organization->postal_code,
        ];

        $missing = array_keys(array_filter($address, fn (mixed $value): bool => blank($value)));

        if ($missing !== []) {
            throw new DomainException(
                'The organization address is incomplete ('.implode(', ', $missing).'). '
                .'Complete it before a branch is created from it.'
            );
        }

        return [...$address, 'additional_number' => $organization->additional_street];
    }

    /**
     * The default branch, promoting an active branch when none is marked.
     */
    private function findDefault(Organization $organization): ?Branch
    {
        $default = $organization->branches()->where('is_default', true)->first();

        if ($default) {
            return $default;
        }

        $active = $organization->branches()->active()->first();
        $active?->setAsDefault();

        return $active;
    }

    /**
     * Whether a branch is one of the organization's, for anything that takes
     * a branch id from a request.
     */
    public function belongsTo(string $branchId, string $organizationId): bool
    {
        return Branch::acrossTenants()
            ->whereKey($branchId)
            ->where('org_id', $organizationId)
            ->exists();
    }

    /** One of the organization's branches, or null when it is not one of them. */
    public function find(string $organizationId, string $branchId): ?Branch
    {
        return Branch::acrossTenants()
            ->whereKey($branchId)
            ->where('org_id', $organizationId)
            ->first();
    }

    /**
     * The organization's branches in till order, for its branch screens.
     *
     * @return Collection<int, Branch>
     */
    public function list(string $organizationId): Collection
    {
        return Branch::acrossTenants()
            ->where('org_id', $organizationId)
            ->orderBy('till_number')
            ->orderBy('created_at')
            ->get();
    }

    /**
     * The organization's tills, in the order they were added: id, name and
     * till number.
     *
     * @return list<array{id: string, name: string, till_number: int|null}>
     */
    public function tills(string $organizationId): array
    {
        return Branch::acrossTenants()
            ->where('org_id', $organizationId)
            ->orderBy('till_number')
            ->get(['id', 'name', 'till_number'])
            ->map(fn (Branch $branch): array => ['id' => (string) $branch->id, 'name' => (string) $branch->name, 'till_number' => $branch->till_number])
            ->all();
    }

    /** A branch's till number, a removed branch's included; null for none. */
    public function tillNumber(string $branchId): ?int
    {
        return Branch::acrossTenants()->withTrashed()->whereKey($branchId)->value('till_number');
    }

    /**
     * List branches for an organization with optional filters.
     *
     * @return Collection<int, Branch>
     */
    public function listFiltered(Organization $organization, ?string $status = null, bool $activeOnly = false, bool $zatcaReady = false): Collection
    {
        $query = $organization->branches();

        if ($status !== null) {
            $query->where('onboarding_status', $status);
        }

        if ($activeOnly) {
            $query->active();
        }

        if ($zatcaReady) {
            $query->fatooraReady();
        }

        return $query->orderBy('is_default', 'desc')
            ->orderBy('name')
            ->get();
    }

    /**
     * Format a branch for API responses.
     *
     * @return array<string, mixed>
     */
    public function format(Branch $branch, bool $detailed = false): array
    {
        $data = [
            'id' => $branch->id,
            'name' => $branch->name,
            'name_ar' => $branch->name_ar,
            'device_serial' => $branch->device_serial,
            'onboarding_status' => $branch->onboarding_status,
            'is_fatoora_ready' => $branch->isFatooraReady(),
            'is_active' => $branch->is_active,
            'is_default' => $branch->is_default,
            'invoice_count' => $branch->invoice_count,
        ];

        if ($detailed) {
            $data = array_merge($data, [
                'industry' => $branch->industry,
                'address' => [
                    'street' => $branch->street,
                    'building_number' => $branch->building_number,
                    'additional_number' => $branch->additional_number,
                    'district' => $branch->district,
                    'city' => $branch->city,
                    'postal_code' => $branch->postal_code,
                    'country_code' => $branch->country_code,
                ],
                'cert_expires_at' => $branch->cert_expires_at?->toIso8601String(),
                'days_until_expiry' => $branch->getDaysUntilCertificateExpiry(),
                'is_certificate_expiring_soon' => $branch->isCertificateExpiringSoon(),
                'onboarded_at' => $branch->onboarded_at?->toIso8601String(),
                'last_invoice_at' => $branch->last_invoice_at?->toIso8601String(),
                'created_at' => $branch->created_at->toIso8601String(),
                'updated_at' => $branch->updated_at->toIso8601String(),
            ]);
        }

        return $data;
    }

    /**
     * Onboarding progress summary for a branch.
     *
     * @return array<string, mixed>
     */
    public function onboardingSummary(Organization $organization, Branch $branch): array
    {
        $devices = $this->jurisdictions->forRules($organization)->devices();
        $hasCcsid = $devices->holds((string) $organization->id, (string) $branch->id, 'ccsid');
        $hasPcsid = $devices->holds((string) $organization->id, (string) $branch->id, 'pcsid');

        return [
            'branch_id' => $branch->id,
            'branch_name' => $branch->name,
            'onboarding_status' => $branch->onboarding_status,
            'has_ccsid' => $hasCcsid,
            'has_pcsid' => $hasPcsid,
            'is_fatoora_ready' => $branch->isFatooraReady(),
            'cert_expires_at' => $branch->cert_expires_at?->toIso8601String(),
            'days_until_expiry' => $branch->getDaysUntilCertificateExpiry(),
            'steps' => [
                'step_1_ccsid' => $hasCcsid ? 'completed' : 'pending',
                'step_2_compliance' => $branch->onboarding_status !== Branch::STATUS_PENDING
                    ? ($hasPcsid ? 'completed' : 'in_progress')
                    : 'pending',
                'step_3_pcsid' => $hasPcsid ? 'completed' : 'pending',
            ],
            'next_action' => $this->getNextAction($branch, $hasCcsid, $hasPcsid),
        ];
    }

    /**
     * Get next action hint for onboarding.
     */
    public function getNextAction(Branch $branch, bool $hasCcsid, bool $hasPcsid): string
    {
        if ($branch->onboarding_status === Branch::STATUS_ACTIVE) {
            return 'Ready to issue invoices';
        }

        if ($branch->onboarding_status === Branch::STATUS_REVOKED) {
            return 'Re-onboard to obtain new certificate';
        }

        if (! $hasCcsid) {
            return 'Obtain a one-time password from the tax authority portal and call POST /onboarding/ccsid';
        }

        if (! $hasPcsid) {
            return 'Run compliance check and call POST /onboarding/pcsid';
        }

        return 'Unknown state';
    }
}
