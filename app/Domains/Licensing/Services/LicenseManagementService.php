<?php

declare(strict_types=1);

namespace App\Domains\Licensing\Services;

use App\Domains\Licensing\Enums\LicenseStatus;
use App\Domains\Licensing\Enums\LicenseTier;
use App\Domains\Licensing\Models\License;
use App\Domains\Licensing\Models\LicenseAuditLog;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
            'invoices_per_month' => $data['invoices_per_month'] ?? $defaults['invoices_per_month'],
            'calls_per_day' => $data['calls_per_day'] ?? $defaults['calls_per_day'],
            'calls_per_min' => $data['calls_per_min'] ?? $defaults['calls_per_min'],
            'max_organizations' => $data['max_organizations'] ?? $defaults['max_organizations'],
            // getDefaults() has no 'features' key — the tier's features come
            // from getDefaultFeatures(). Reading the missing key raised
            // "Undefined array key", so creating a licence without an explicit
            // feature list threw before it created anything.
            'features' => $data['features'] ?? $tier->getDefaultFeatures(),
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

        return $license->fresh();
    }

    /**
     * Reactivate a suspended license.
     */
    public function reactivateLicense(string $licenseId): License
    {
        $license = License::findOrFail($licenseId);
        $license->reactivate();

        return $license->fresh();
    }

    /**
     * Revoke a license permanently.
     */
    public function revokeLicense(string $licenseId, ?string $reason = null): License
    {
        $license = License::findOrFail($licenseId);
        $license->revoke($reason);

        return $license->fresh();
    }

    /**
     * Extend license expiration.
     */
    public function extendLicense(string $licenseId, int $days): License
    {
        $license = License::findOrFail($licenseId);
        $license->extend($days);

        return $license->fresh();
    }

    /**
     * Upgrade license tier.
     */
    public function upgradeTier(string $licenseId, string $newTier): License
    {
        $license = License::findOrFail($licenseId);
        $license->upgradeTier(LicenseTier::from($newTier));

        return $license->fresh();
    }

    /**
     * Update license limits.
     */
    public function updateLimits(string $licenseId, array $limits): License
    {
        $license = License::findOrFail($licenseId);

        $allowedFields = [
            'invoices_per_month',
            'calls_per_day',
            'calls_per_min',
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
            'api_secret_hash' => License::hashSecret($newSecret),
        ]);

        $this->logAudit($licenseId, 'secret_regenerated');

        // The cache entry is dropped by the model on save. This forgot
        // "license:{api_key}" while the cache stores the digest of the key, so
        // it cleared nothing and the old secret kept working until the entry
        // expired.

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
                // Named for the columns the table has: event, actor_type and
                // actor_id. Reading action, details or performed_by yields null.
                'event' => $log->event,
                'actor_type' => $log->actor_type,
                'actor_id' => $log->actor_id,
                'old_values' => json_decode((string) $log->old_values, true),
                'new_values' => json_decode((string) $log->new_values, true),
                'reason' => $log->reason,
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
            // Through the model, which knows the table's shape and enforces
            // append-only. A raw insert naming action, details, performed_by or
            // user_agent throws — the table has none of them — and the catch
            // below would turn each failure into a log line and an empty audit
            // trail.
            LicenseAuditLog::create([
                'license_id' => $licenseId,
                'event' => $action,
                'actor_type' => $performedBy !== null ? 'user' : 'system',
                'actor_id' => $performedBy ?? Auth::id(),
                'ip_address' => request()->ip(),
                'new_values' => $details,
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
