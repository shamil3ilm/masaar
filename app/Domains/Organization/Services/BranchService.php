<?php

declare(strict_types=1);

namespace App\Domains\Organization\Services;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\CredentialStore;
use App\Domains\Organization\Exceptions\BranchInUseException;
use App\Domains\Organization\Models\Branch;
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
        private readonly CredentialStore $credentials,
        private readonly AuditService $audit,
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

            // Generate device serial if not provided
            $deviceSerial = $data['device_serial'] ?? Branch::generateDeviceSerial($organization);

            $branch = Branch::create([
                'org_id' => $organization->id,
                'name' => $data['name'],
                'name_ar' => $data['name_ar'] ?? null,
                'device_serial' => $deviceSerial,
                'industry' => $data['industry'] ?? 'General',
                'street' => $data['street'],
                'building_number' => $data['building_number'],
                'additional_number' => $data['additional_number'] ?? null,
                'district' => $data['district'],
                'city' => $data['city'],
                'postal_code' => $data['postal_code'],
                'country_code' => $data['country_code'] ?? 'SA',
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
        ], fn ($v) => $v !== null);

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

            return (bool) $branch->delete();
        });

        // Credentials are files outside the transaction, so they go only once
        // the row is gone. In the other order a failed delete leaves a branch
        // that can no longer sign.
        if ($deleted) {
            $this->deleteCredentials($branch);
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
     * Store branch credentials securely.
     */
    /**
     * Whether a branch is one of the organization's, for anything that takes
     * a branch id from a request.
     */
    public function belongsTo(string $branchId, string $organizationId): bool
    {
        return Branch::withoutTenantScope(fn (): bool => Branch::query()
            ->whereKey($branchId)
            ->where('org_id', $organizationId)
            ->exists());
    }

    /** One of the organization's branches, or null when it is not one of them. */
    public function find(string $organizationId, string $branchId): ?Branch
    {
        return Branch::withoutTenantScope(fn (): ?Branch => Branch::query()
            ->whereKey($branchId)
            ->where('org_id', $organizationId)
            ->first());
    }

    /**
     * The organization's branches in till order, for its branch screens.
     *
     * @return Collection<int, Branch>
     */
    public function list(string $organizationId): Collection
    {
        return Branch::withoutTenantScope(fn () => Branch::query()
            ->where('org_id', $organizationId)
            ->orderBy('till_number')
            ->orderBy('created_at')
            ->get());
    }

    /**
     * The organization's tills, in the order they were added: id, name and
     * till number.
     *
     * @return list<array{id: string, name: string, till_number: int|null}>
     */
    public function tills(string $organizationId): array
    {
        return Branch::withoutTenantScope(fn (): array => Branch::query()
            ->where('org_id', $organizationId)
            ->orderBy('till_number')
            ->get(['id', 'name', 'till_number'])
            ->map(fn (Branch $branch): array => ['id' => (string) $branch->id, 'name' => (string) $branch->name, 'till_number' => $branch->till_number])
            ->all());
    }

    /** A branch's till number, a removed branch's included; null for none. */
    public function tillNumber(string $branchId): ?int
    {
        return Branch::withoutTenantScope(fn (): ?int => Branch::withTrashed()->whereKey($branchId)->value('till_number'));
    }

    public function storeCredentials(Branch $branch, string $type, array $data): void
    {
        $this->credentials->put($branch->org_id, $branch->id, $type, $data);
    }

    /**
     * Get branch credentials.
     */
    public function getCredentials(Branch $branch, string $type): ?array
    {
        return $this->credentials->get($branch->org_id, $branch->id, $type);
    }

    /**
     * Delete branch credentials.
     */
    public function deleteCredentials(Branch $branch): void
    {
        $this->credentials->forget($branch->org_id, $branch->id);
    }

    /**
     * Check if branch has PCSID credentials.
     */
    public function hasPcsid(Branch $branch): bool
    {
        return $this->getCredentials($branch, 'pcsid') !== null;
    }
}
