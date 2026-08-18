<?php

declare(strict_types=1);

namespace App\Domains\Organization\Services;

use App\Domains\Organization\Models\Branch;
use App\Domains\Organization\Models\Organization;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Service for managing organization branches (EGS units).
 *
 * Handles branch CRUD operations and credential storage for multi-EGS support.
 */
class BranchService
{
    /**
     * Create a new branch for an organization.
     */
    public function create(Organization $organization, array $data): Branch
    {
        return DB::transaction(function () use ($organization, $data) {
            // Generate device serial if not provided
            $deviceSerial = $data['device_serial'] ?? Branch::generateDeviceSerial($organization);

            $branch = Branch::create([
                'organization_id' => $organization->id,
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

        $branch->update($updateData);

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
            throw new \Exception('Cannot delete branch with existing invoices. Suspend instead.');
        }

        // Check if default and other branches exist
        if ($branch->is_default) {
            $otherBranch = Branch::where('organization_id', $branch->organization_id)
                ->where('id', '!=', $branch->id)
                ->active()
                ->first();

            if ($otherBranch) {
                $otherBranch->setAsDefault();
            }
        }

        // Delete credentials
        $this->deleteCredentials($branch);

        return $branch->delete();
    }

    /**
     * Get or create default branch for organization.
     *
     * Creates a "Main Branch" if no branches exist (backward compatibility).
     */
    public function getOrCreateDefault(Organization $organization): Branch
    {
        $defaultBranch = $organization->branches()
            ->where('is_default', true)
            ->first();

        if ($defaultBranch) {
            return $defaultBranch;
        }

        // Check for any active branch
        $anyBranch = $organization->branches()->active()->first();
        if ($anyBranch) {
            $anyBranch->setAsDefault();

            return $anyBranch;
        }

        // Create default branch using organization address
        return $this->create($organization, [
            'name' => 'Main Branch',
            'name_ar' => 'الفرع الرئيسي',
            'street' => $organization->street ?? 'Main Street',
            'building_number' => $organization->building_number ?? '0001',
            'additional_number' => $organization->additional_street,
            'district' => $organization->district ?? 'Central',
            'city' => $organization->city ?? 'Riyadh',
            'postal_code' => $organization->postal_code ?? '00000',
        ]);
    }

    /**
     * Store branch credentials securely.
     */
    public function storeCredentials(Branch $branch, string $type, array $data): void
    {
        $path = $this->getCredentialsPath($branch, $type);
        Storage::disk('local')->put($path, encrypt(json_encode($data)));
    }

    /**
     * Get branch credentials.
     */
    public function getCredentials(Branch $branch, string $type): ?array
    {
        $path = $this->getCredentialsPath($branch, $type);

        if (! Storage::disk('local')->exists($path)) {
            return null;
        }

        $content = Storage::disk('local')->get($path);

        return json_decode(decrypt($content), true);
    }

    /**
     * Delete branch credentials.
     */
    public function deleteCredentials(Branch $branch): void
    {
        $basePath = "zatca/{$branch->organization_id}/branches/{$branch->id}";

        foreach (['ccsid', 'pcsid'] as $type) {
            $path = "{$basePath}/{$type}.json";
            if (Storage::disk('local')->exists($path)) {
                Storage::disk('local')->delete($path);
            }
        }
    }

    /**
     * Check if branch has PCSID credentials.
     */
    public function hasPcsid(Branch $branch): bool
    {
        return $this->getCredentials($branch, 'pcsid') !== null;
    }

    /**
     * Get branches with expiring certificates.
     */
    public function getExpiringCertificates(int $days = 30): Collection
    {
        return Branch::certificateExpiringSoon($days)
            ->with('organization')
            ->get();
    }

    /**
     * Get credential storage path.
     */
    private function getCredentialsPath(Branch $branch, string $type): string
    {
        return "zatca/{$branch->organization_id}/branches/{$branch->id}/{$type}.json";
    }

    /**
     * Migrate legacy organization credentials to default branch.
     *
     * For backward compatibility with organizations that have credentials
     * stored at organization level (before branch support).
     */
    public function migrateLegacyCredentials(Organization $organization): ?Branch
    {
        $legacyCcsidPath = "zatca/{$organization->id}/ccsid.json";
        $legacyPcsidPath = "zatca/{$organization->id}/pcsid.json";

        // Check if legacy credentials exist
        $hasCcsid = Storage::disk('local')->exists($legacyCcsidPath);
        $hasPcsid = Storage::disk('local')->exists($legacyPcsidPath);

        if (! $hasCcsid && ! $hasPcsid) {
            return null;
        }

        // Get or create default branch
        $branch = $this->getOrCreateDefault($organization);

        // Copy credentials to branch location
        if ($hasCcsid) {
            $content = Storage::disk('local')->get($legacyCcsidPath);
            $this->storeCredentials($branch, 'ccsid', json_decode(decrypt($content), true));
        }

        if ($hasPcsid) {
            $content = Storage::disk('local')->get($legacyPcsidPath);
            $this->storeCredentials($branch, 'pcsid', json_decode(decrypt($content), true));

            // Mark branch as active
            $branch->markAsActive();
        }

        return $branch;
    }
}
