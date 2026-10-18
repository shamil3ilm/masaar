<?php

declare(strict_types=1);

namespace App\Domains\Organization\Services;

use App\Domains\Audit\Attribution\Origin;
use App\Domains\Organization\Models\Organization;
use App\Support\CountryClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A platform admin's grace for one organization: until a date, an invoice
 * missing the text its country's language rules require is drafted with a
 * warning instead of refused, so a customer with years of English-only
 * products and customers can keep invoicing while it fills in the Arabic.
 *
 * Off unless granted, and over on its own: it is active through the date it
 * names, on the organization's own clock, and nothing has to switch it off.
 * Granting it takes a reason, and the console records who changed it with
 * the state before and after (AuditAdminAction). Granting or extending it
 * is an admin action an approval policy can hold (LanguageGraceActions).
 *
 * Kept in language_graces, one row per organization, read and written here
 * and nowhere else, so a settings store can take it over by changing this
 * class alone.
 */
class LanguageGrace
{
    private const TABLE = 'language_graces';

    public function __construct(
        private readonly Origin $origin,
    ) {}

    /** Whether missing text only warns for this organization today. */
    public function active(?Organization $organization): bool
    {
        $until = $this->until($organization);

        return $until !== null && ! $this->today($organization)->greaterThan($until);
    }

    /** The last day the grace covers; null when there is none. */
    public function until(?Organization $organization): ?CarbonImmutable
    {
        $until = $this->row($organization)?->until;

        return blank($until) ? null : CarbonImmutable::parse((string) $until)->startOfDay();
    }

    /**
     * The grace as the console shows it, active or lapsed; null when none
     * was ever granted or it was withdrawn.
     *
     * @return array{until: string, reason: string, by: string|null, active: bool}|null
     */
    public function describe(Organization $organization): ?array
    {
        $row = $this->row($organization);
        $until = $this->until($organization);

        return $until === null ? null : [
            'until' => $until->toDateString(),
            'reason' => (string) $row?->reason,
            'by' => $row?->updated_by === null ? null : DB::table('users')->where('id', $row->updated_by)->value('email'),
            'active' => $this->active($organization),
        ];
    }

    /**
     * Grant it until a date, or withdraw it with null. Who did is the person
     * acting (Origin), unless the caller names the user who did: a grant an
     * approval policy held (LanguageGraceActions) names who asked.
     */
    public function set(Organization $organization, ?CarbonImmutable $until, string $reason, ?string $actorId = null): void
    {
        $actorId ??= $this->origin->actorId();

        $values = [
            'until' => $until?->toDateString(),
            'reason' => $until === null ? null : $reason,
            'updated_by' => $actorId,
            'updated_at' => now(),
        ];

        $updated = DB::table(self::TABLE)->where('org_id', $organization->id)->update($values);

        if ($updated === 0 && $until !== null) {
            DB::table(self::TABLE)->insert([...$values, 'created_by' => $actorId, 'id' => (string) Str::uuid7(), 'org_id' => $organization->id, 'created_at' => now()]);
        }
    }

    private function row(?Organization $organization): ?object
    {
        return $organization === null ? null : DB::table(self::TABLE)->where('org_id', $organization->id)->first();
    }

    private function today(?Organization $organization): CarbonImmutable
    {
        return CarbonImmutable::parse(CountryClock::now($organization?->country)->toDateString())->startOfDay();
    }
}
