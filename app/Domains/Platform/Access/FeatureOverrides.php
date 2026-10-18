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
        return $this->cached($organizationId)['organization'];
    }

    /**
     * The organization's switches for its roles and people, by subject
     * ('role:{role}' or 'user:{id}') and feature.
     *
     * @return array<string, array<string, array{state: string, reason: string, by: string|null, at: string|null}>>
     */
    public function forPeople(string $organizationId): array
    {
        return $this->cached($organizationId)['people'];
    }

    public static function role(string $role): string
    {
        return 'role:'.$role;
    }

    public static function person(string $userId): string
    {
        return 'user:'.$userId;
    }

    /** 'off', 'on', or null for the default. */
    public function state(string $organizationId, Feature $feature): ?string
    {
        return $this->for($organizationId)[$feature->value]['state'] ?? null;
    }

    /**
     * Set the switch, or clear it with null, recording the reason and who:
     * the person acting (RecordsActors), unless the caller names the user
     * who did - an approved request names who asked.
     */
    public function set(string $organizationId, Feature $feature, ?string $state, string $reason, ?string $actorId = null, string $subject = ''): void
    {
        DB::transaction(function () use ($organizationId, $feature, $state, $reason, $actorId, $subject): void {
            $query = FeatureOverride::query()->where('org_id', $organizationId)->where('feature', $feature->value)->where('subject', $subject);

            if ($state === null) {
                $query->delete();

                return;
            }

            $switch = FeatureOverride::query()->firstOrNew(['org_id' => $organizationId, 'feature' => $feature->value, 'subject' => $subject]);
            $switch->fill(['state' => $state, 'reason' => $reason]);

            if ($actorId !== null) {
                $switch->forceFill(['set_by' => $actorId]);
            }

            $switch->save();
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
     * @return array{organization: array<string, array{state: string, reason: string, by: string|null, at: string|null}>, people: array<string, array<string, array{state: string, reason: string, by: string|null, at: string|null}>>}
     */
    private function cached(string $organizationId): array
    {
        return Cache::rememberForever(self::CACHE_KEY.$organizationId, fn (): array => $this->read($organizationId));
    }

    /**
     * @return array{organization: array<string, array{state: string, reason: string, by: string|null, at: string|null}>, people: array<string, array<string, array{state: string, reason: string, by: string|null, at: string|null}>>}
     */
    private function read(string $organizationId): array
    {
        $switches = ['organization' => [], 'people' => []];

        $rows = FeatureOverride::query()->where('org_id', $organizationId)->get();
        $emails = DB::table('users')->whereIn('id', $rows->pluck('set_by')->filter()->unique()->all())->pluck('email', 'id');

        foreach ($rows as $row) {
            $switch = [
                'state' => $row->state,
                'reason' => $row->reason,
                'by' => $row->set_by === null ? null : ($emails[$row->set_by] ?? null),
                'at' => $row->updated_at?->toIso8601String(),
            ];

            if ((string) $row->subject === '') {
                $switches['organization'][$row->feature] = $switch;
            } else {
                $switches['people'][$row->subject][$row->feature] = $switch;
            }
        }

        return $switches;
    }
}
