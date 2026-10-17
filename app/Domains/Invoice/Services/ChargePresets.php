<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Services;

use App\Domains\Invoice\Models\ChargePreset;
use Illuminate\Support\Facades\DB;

/**
 * The common charges an invoice form offers - delivery, a service charge,
 * packaging - as three lists, each overriding the one above it by key: the
 * platform's, the organization's, and the person's own. A level can change
 * an entry above it, add its own, or hide one (disabled).
 *
 * The rule every customization follows: platform default, organization
 * override, user override. Kept to this one list behind this one class, so
 * moving it into a general settings layer later changes only this.
 */
class ChargePresets
{
    /** What an entry carries, apart from its key and level. */
    private const FIELDS = ['reason', 'reason_code', 'percent', 'amount', 'tax_category', 'tax_rate'];

    /**
     * The list a person in an organization sees, in the platform's order
     * with the organization's and then the person's own after it.
     *
     * @return list<array{key: string, reason: string, reason_code: string, percent: ?string, amount: ?string, tax_category: ?string, tax_rate: ?string}>
     */
    public function for(string $organizationId, ?string $userId = null): array
    {
        $levels = [
            $this->level(null, null),
            $this->level($organizationId, null),
            $userId === null ? [] : $this->level($organizationId, $userId),
        ];

        $merged = [];

        foreach ($levels as $level) {
            foreach ($level as $key => $preset) {
                $merged[$key] = $preset->disabled ? null : ['key' => $key, ...$preset->only(self::FIELDS)];
            }
        }

        return array_values(array_filter($merged));
    }

    /**
     * Add or change an entry at a level: the platform's with no
     * organization, an organization's, or a person's with both.
     *
     * @param  array<string, mixed>  $preset  {key, reason, reason_code, percent?, amount?, tax_category?, tax_rate?}
     */
    public function save(?string $organizationId, ?string $userId, array $preset): ChargePreset
    {
        return DB::transaction(fn (): ChargePreset => ChargePreset::query()->updateOrCreate(
            ['org_id' => $organizationId, 'user_id' => $userId, 'key' => (string) $preset['key']],
            [...array_intersect_key($preset, array_flip(self::FIELDS)), 'disabled' => false],
        ));
    }

    /**
     * Hide an entry at a level, whichever level it came from: the level's
     * own row is removed, and one from above is covered by a disabled row.
     */
    public function hide(?string $organizationId, ?string $userId, string $key): void
    {
        DB::transaction(function () use ($organizationId, $userId, $key): void {
            $own = ChargePreset::query()->where(['org_id' => $organizationId, 'user_id' => $userId, 'key' => $key])->first();
            $above = collect($organizationId === null ? [] : [$this->level(null, null), $userId === null ? [] : $this->level($organizationId, null)])
                ->contains(fn (array $level): bool => isset($level[$key]));

            if ($above) {
                ChargePreset::query()->updateOrCreate(
                    ['org_id' => $organizationId, 'user_id' => $userId, 'key' => $key],
                    ['reason' => $own->reason ?? $key, 'reason_code' => $own->reason_code ?? 'ABK', 'disabled' => true],
                );

                return;
            }

            $own?->delete();
        });
    }

    /**
     * One level's entries, by key.
     *
     * @return array<string, ChargePreset>
     */
    private function level(?string $organizationId, ?string $userId): array
    {
        return ChargePreset::query()
            ->when($organizationId === null, fn ($q) => $q->whereNull('org_id'), fn ($q) => $q->where('org_id', $organizationId))
            ->when($userId === null, fn ($q) => $q->whereNull('user_id'), fn ($q) => $q->where('user_id', $userId))
            ->orderBy('position')
            ->orderBy('key')
            ->get()
            ->keyBy('key')
            ->all();
    }
}
