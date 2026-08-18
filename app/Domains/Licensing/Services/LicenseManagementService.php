<?php

declare(strict_types=1);

namespace App\Domains\Licensing\Services;

use App\Domains\Licensing\Enums\LicenseStatus;
use App\Domains\Licensing\Enums\LicenseTier;
use App\Domains\Licensing\Models\License;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * License Management Service.
 *
 * Handles administrative operations for license management.
 */
class LicenseManagementService
{
    public function __construct(
        private readonly UsageMeteringService $meteringService,
    ) {}

    /**
     * Create a new license.
     *
     * @return array{license: License, api_key: string, api_secret: string}
     */
    public function createLicense(array $data): array
    {
        $tier = LicenseTier::from($data['tier'] ?? 'starter');
        $defaults = $tier->getDefaults();

        // Generate credentials
        $result = License::createWithCredentials([
            'organization_name' => $data['organization_name'],
            'contact_email' => $data['contact_email'],
            'organization_vat' => $data['organization_vat'] ?? null,
            'contact_phone' => $data['contact_phone'] ?? null,
            'tier' => $tier,
            'status' => LicenseStatus::from($data['status'] ?? 'pending_activation'),
            'max_invoices_per_month' => $data['max_invoices_per_month'] ?? $defaults['max_invoices_per_month'],
            'max_api_calls_per_day' => $data['max_api_calls_per_day'] ?? $defaults['max_api_calls_per_day'],
            'max_api_calls_per_minute' => $data['max_api_calls_per_minute'] ?? $defaults['max_api_calls_per_minute'],
            'max_organizations' => $data['max_organizations'] ?? $defaults['max_organizations'],
            'features' => $data['features'] ?? $defaults['features'],
            'expires_at' => isset($data['expires_at']) ? new \DateTime($data['expires_at']) : null,
            'notes' => $data['notes'] ?? null,
        ]);

        $this->logAudit($result['license']->id, 'created', [
            'tier' => $tier->value,
            'organization_name' => $data['organization_name'],
        ]);

        return $result;
    }

    /**
     * List licenses with filters.
     */
    public function listLicenses(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $query = License::query();

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['tier'])) {
            $query->where('tier', $filters['tier']);
        }

        if (isset($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('organization_name', 'like', "%{$search}%")
                    ->orWhere('contact_email', 'like', "%{$search}%")
                    ->orWhere('api_key', 'like', "%{$search}%");
            });
        }

        if (isset($filters['expired'])) {
            if ($filters['expired']) {
                $query->where('expires_at', '<', now());
            } else {
                $query->where(function ($q) {
                    $q->whereNull('expires_at')
                        ->orWhere('expires_at', '>=', now());
                });
            }
        }

        return $query->orderBy('created_at', 'desc')->paginate($perPage);
    }

    /**
     * Get license details with usage.
     */
    public function getLicenseDetails(string $licenseId): array
    {
        $license = License::findOrFail($licenseId);
        $usage = $this->meteringService->getUsageSummary($license);

        return [
            'license' => $license->toArray(),
            'usage' => $usage,
            'is_valid' => $license->isValid(),
            'days_until_expiry' => $license->expires_at
                ? now()->diffInDays($license->expires_at, false)
                : null,
        ];
    }

    /**
     * Activate a pending license.
     */
    public function activateLicense(string $licenseId): License
    {
        $license = License::findOrFail($licenseId);

        if ($license->status !== LicenseStatus::PendingActivation) {
            throw new \RuntimeException("License is not pending activation. Current status: {$license->status->value}");
        }

        $license->update([
            'status' => LicenseStatus::Active,
            'activated_at' => now(),
        ]);

        $this->logAudit($licenseId, 'activated');

        return $license->fresh();
    }

    /**
     * Suspend a license.
     */
    public function suspendLicense(string $licenseId, ?string $reason = null): License
    {
        $license = License::findOrFail($licenseId);
        $license->suspend($reason);

        $this->logAudit($licenseId, 'suspended', ['reason' => $reason]);

        return $license->fresh();
    }

    /**
     * Reactivate a suspended license.
     */
    public function reactivateLicense(string $licenseId): License
    {
        $license = License::findOrFail($licenseId);
        $license->reactivate();

        $this->logAudit($licenseId, 'reactivated');

        return $license->fresh();
    }

    /**
     * Revoke a license permanently.
     */
    public function revokeLicense(string $licenseId, ?string $reason = null): License
    {
        $license = License::findOrFail($licenseId);
        $license->revoke($reason);

        $this->logAudit($licenseId, 'revoked', ['reason' => $reason]);

        return $license->fresh();
    }

    /**
     * Extend license expiration.
     */
    public function extendLicense(string $licenseId, int $days): License
    {
        $license = License::findOrFail($licenseId);
        $oldExpiry = $license->expires_at?->toDateString();

        $license->extend($days);

        $this->logAudit($licenseId, 'extended', [
            'old_expiry' => $oldExpiry,
            'new_expiry' => $license->fresh()->expires_at?->toDateString(),
            'days_added' => $days,
        ]);

        return $license->fresh();
    }

    /**
     * Upgrade license tier.
     */
    public function upgradeTier(string $licenseId, string $newTier): License
    {
        $license = License::findOrFail($licenseId);
        $oldTier = $license->tier->value;

        $license->upgradeTier(LicenseTier::from($newTier));

        $this->logAudit($licenseId, 'tier_upgraded', [
            'old_tier' => $oldTier,
            'new_tier' => $newTier,
        ]);

        return $license->fresh();
    }

    /**
     * Update license limits.
     */
    public function updateLimits(string $licenseId, array $limits): License
    {
        $license = License::findOrFail($licenseId);

        $allowedFields = [
            'max_invoices_per_month',
            'max_api_calls_per_day',
            'max_api_calls_per_minute',
            'max_organizations',
        ];

        $updates = array_intersect_key($limits, array_flip($allowedFields));

        if (! empty($updates)) {
            $license->update($updates);
            $this->logAudit($licenseId, 'limits_updated', $updates);
        }

        return $license->fresh();
    }

    /**
     * Update license features.
     */
    public function updateFeatures(string $licenseId, array $features): License
    {
        $license = License::findOrFail($licenseId);

        $license->update(['features' => $features]);
        $this->logAudit($licenseId, 'features_updated', ['features' => $features]);

        return $license->fresh();
    }

    /**
     * Regenerate API secret (keep API key).
     *
     * @return array{license: License, api_secret: string}
     */
    public function regenerateSecret(string $licenseId): array
    {
        $license = License::findOrFail($licenseId);

        // Generate new secret
        $newSecret = bin2hex(random_bytes(32));
        $license->update([
            'api_secret_hash' => hash('sha256', $newSecret),
        ]);

        $this->logAudit($licenseId, 'secret_regenerated');

        // Clear cached license
        Cache::forget("license:{$license->api_key}");

        return [
            'license' => $license->fresh(),
            'api_secret' => $newSecret,
        ];
    }

    /**
     * Get license statistics.
     */
    public function getStatistics(): array
    {
        $licenses = License::query();

        return [
            'total' => $licenses->count(),
            'by_status' => [
                'active' => License::where('status', LicenseStatus::Active)->count(),
                'suspended' => License::where('status', LicenseStatus::Suspended)->count(),
                'expired' => License::where('status', LicenseStatus::Expired)->count(),
                'revoked' => License::where('status', LicenseStatus::Revoked)->count(),
                'pending' => License::where('status', LicenseStatus::PendingActivation)->count(),
            ],
            'by_tier' => [
                'starter' => License::where('tier', LicenseTier::Starter)->count(),
                'professional' => License::where('tier', LicenseTier::Professional)->count(),
                'enterprise' => License::where('tier', LicenseTier::Enterprise)->count(),
                'unlimited' => License::where('tier', LicenseTier::Unlimited)->count(),
            ],
            'expiring_soon' => License::where('status', LicenseStatus::Active)
                ->whereNotNull('expires_at')
                ->where('expires_at', '<=', now()->addDays(30))
                ->count(),
        ];
    }

    /**
     * Get audit log for a license.
     */
    public function getAuditLog(string $licenseId, int $limit = 50): array
    {
        return DB::table('license_audit_logs')
            ->where('license_id', $licenseId)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get()
            ->map(fn ($log) => [
                'id' => $log->id,
                'action' => $log->action,
                'details' => json_decode($log->details, true),
                'performed_by' => $log->performed_by,
                'ip_address' => $log->ip_address,
                'created_at' => $log->created_at,
            ])
            ->toArray();
    }

    /**
     * Log an audit entry.
     */
    private function logAudit(
        string $licenseId,
        string $action,
        array $details = [],
        ?string $performedBy = null
    ): void {
        if (! config('licensing.audit.enabled', true)) {
            return;
        }

        try {
            DB::table('license_audit_logs')->insert([
                'id' => Str::uuid()->toString(),
                'license_id' => $licenseId,
                'action' => $action,
                'details' => json_encode($details),
                'performed_by' => $performedBy ?? auth()->id(),
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'created_at' => now(),
            ]);
        } catch (\Exception $e) {
            Log::warning('Failed to log license audit', [
                'license_id' => $licenseId,
                'action' => $action,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
