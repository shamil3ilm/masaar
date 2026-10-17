<?php

declare(strict_types=1);

namespace App\Domains\Platform\Access;

use App\Domains\Organization\Models\Organization;
use App\Domains\Platform\Models\FeatureOverride;
use App\Support\Errors\ComplianceException;
use App\Support\Errors\ErrorCode;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * A platform admin's per-organization switches: a feature off for one
 * organization whatever its plan says, or on beyond its plan.
 *
 * Asked on every issue, so each organization's switches are cached as one
 * entry and forgotten when one changes - after the write commits, so a
 * request reading between the write and the commit cannot put the old value
 * back for good.
 */
class FeatureOverrides
{
    private const CACHE_KEY = 'access.overrides.';

    /**
     * Every switch the organization has, by feature.
     *
     * @return array<string, array{state: string, reason: string, by: string|null, at: string|null}>
     */
    public function for(string $organizationId): array
    {
        return Cache::rememberForever(self::CACHE_KEY.$organizationId, fn (): array => $this->read($organizationId));
    }

    /** 'off', 'on', or null for the default. */
    public function state(string $organizationId, Feature $feature): ?string
    {
        return $this->for($organizationId)[$feature->value]['state'] ?? null;
    }

    /**
     * Set the switch, or clear it with null, recording the reason and who.
     */
    public function set(string $organizationId, Feature $feature, ?string $state, string $reason, ?string $actor): void
    {
        DB::transaction(function () use ($organizationId, $feature, $state, $reason, $actor): void {
            $query = FeatureOverride::query()->where('org_id', $organizationId)->where('feature', $feature->value);

            if ($state === null) {
                $query->delete();

                return;
            }

            FeatureOverride::query()->updateOrCreate(
                ['org_id' => $organizationId, 'feature' => $feature->value],
                ['state' => $state, 'reason' => $reason, 'set_by' => $actor],
            );
        });

        Cache::forget(self::CACHE_KEY.$organizationId);
        DB::afterCommit(fn () => Cache::forget(self::CACHE_KEY.$organizationId));
    }

    /**
     * Refuse when a platform admin switched any of these off for the
     * organization. Every country's issuing step and the ERP pipeline ask
     * this, so the switch holds on every path rather than only the portal.
     *
     * @throws ComplianceException AUTH_FEATURE_SWITCHED_OFF
     */
    public function assertNotSwitchedOff(Organization $organization, Feature ...$features): void
    {
        foreach ($features as $feature) {
            if ($this->state((string) $organization->id, $feature) === FeatureOverride::OFF) {
                throw new ComplianceException(
                    __(':feature is switched off for this company by a platform admin. Contact support.', ['feature' => $feature->label()]),
                    ErrorCode::AUTH_FEATURE_SWITCHED_OFF,
                    ['feature' => $feature->value],
                );
            }
        }
    }

    /**
     * @return array<string, array{state: string, reason: string, by: string|null, at: string|null}>
     */
    private function read(string $organizationId): array
    {
        $switches = [];

        foreach (FeatureOverride::query()->where('org_id', $organizationId)->get() as $row) {
            $switches[$row->feature] = [
                'state' => $row->state,
                'reason' => $row->reason,
                'by' => $row->set_by,
                'at' => $row->updated_at?->toIso8601String(),
            ];
        }

        return $switches;
    }
}
