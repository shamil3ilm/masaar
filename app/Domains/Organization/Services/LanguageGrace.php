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
 * the state before and after (AuditAdminAction).
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
     * Grant it until a date, or withdraw it with null.
     *
     * APPROVAL POLICY HOOK: the platform has no approval policies yet, so a
     * grant takes effect at once. When they exist, a grant is where one
     * waits for its approver (see requiresApproval()).
     *
     * @return bool whether it applies now, rather than waiting for approval
     */
    public function set(Organization $organization, ?CarbonImmutable $until, string $reason): bool
    {
        if ($this->requiresApproval($organization, $until)) {
            return false;
        }

        $values = [
            'until' => $until?->toDateString(),
            'reason' => $until === null ? null : $reason,
            ...$this->origin->columns(['updated_by']),
            'updated_at' => now(),
        ];

        $updated = DB::table(self::TABLE)->where('org_id', $organization->id)->update($values);

        if ($updated === 0 && $until !== null) {
            DB::table(self::TABLE)->insert([...$values, ...$this->origin->columns(['created_by']), 'id' => (string) Str::uuid7(), 'org_id' => $organization->id, 'created_at' => now()]);
        }

        return true;
    }

    /**
     * Approval-policy hook: whether this grant has to be approved before it
     * applies. None does until approval policies exist.
     */
    protected function requiresApproval(Organization $organization, ?CarbonImmutable $until): bool
    {
        return false;
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
