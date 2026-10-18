<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Services;

use App\Domains\Platform\Customization\Customizations;
use App\Domains\Platform\Customization\Level;
use App\Domains\Platform\Customization\Scope;

/**
 * The common charges an invoice form offers - delivery, a service charge,
 * packaging - as three lists, each overriding the one above it by key: the
 * platform's, the organization's, and the person's own. A level can change
 * an entry above it, add its own, or hide one.
 *
 * One customization (invoices.charge_presets), a list of entries; the
 * customizations store keeps each level's list and merges them.
 */
class ChargePresets
{
    private const KEY = 'invoices.charge_presets';

    /** What an entry carries, apart from its key. */
    private const FIELDS = ['reason', 'reason_code', 'percent', 'amount', 'tax_category', 'tax_rate'];

    /** Amounts and rates, written with two decimals as the invoice form expects. */
    private const DECIMALS = ['percent', 'amount', 'tax_rate'];

    public function __construct(
        private readonly Customizations $customizations,
    ) {}

    /**
     * The list a person in an organization sees, in the platform's order
     * with the organization's and then the person's own after it.
     *
     * @return list<array{key: string, reason: string, reason_code: string, percent: ?string, amount: ?string, tax_category: ?string, tax_rate: ?string}>
     */
    public function for(string $organizationId, ?string $userId = null): array
    {
        $scope = $userId === null ? Scope::organization($organizationId) : Scope::member($organizationId, $userId);

        return array_map(fn (array $entry): array => $this->shaped($entry), $this->customizations->get(self::KEY, $scope));
    }

    /**
     * Add or change an entry at a level: the platform's with no
     * organization, an organization's, or a person's with both.
     *
     * @param  array<string, mixed>  $preset  {key, reason, reason_code, percent?, amount?, tax_category?, tax_rate?}
     * @return array<string, mixed> the entry as saved
     */
    public function save(?string $organizationId, ?string $userId, array $preset): array
    {
        [$level, $scope] = $this->level($organizationId, $userId);
        $entry = ['key' => (string) $preset['key'], ...array_intersect_key($preset, array_flip(self::FIELDS))];
        $own = $this->own($level, $scope);
        $index = $this->index($own, $entry['key']);

        if ($index === null) {
            $own[] = $entry;
        } else {
            $own[$index] = $entry;
        }

        $this->customizations->set(self::KEY, $level, $scope, $own, $userId);

        return $entry;
    }

    /**
     * Hide an entry at a level, whichever level it came from: the level's
     * own entry is removed, and one from above is covered by a hidden one.
     */
    public function hide(?string $organizationId, ?string $userId, string $key): void
    {
        [$level, $scope] = $this->level($organizationId, $userId);
        $own = $this->own($level, $scope);
        $index = $this->index($own, $key);
        $above = $this->above($organizationId, $userId);

        if ($index !== null) {
            unset($own[$index]);
            $own = array_values($own);
        }

        if (in_array($key, array_column($above, 'key'), true)) {
            $own[] = ['key' => $key, 'hidden' => true];
        }

        $own === []
            ? $this->customizations->reset(self::KEY, $level, $scope, $userId)
            : $this->customizations->set(self::KEY, $level, $scope, $own, $userId);
    }

    /**
     * What the levels above this one offer, merged.
     *
     * @return list<array<string, mixed>>
     */
    private function above(?string $organizationId, ?string $userId): array
    {
        return match (true) {
            $organizationId === null => [],
            $userId === null => $this->customizations->get(self::KEY, Scope::platform()),
            default => $this->customizations->get(self::KEY, Scope::organization($organizationId)),
        };
    }

    /**
     * @return array{0: Level, 1: Scope}
     */
    private function level(?string $organizationId, ?string $userId): array
    {
        return match (true) {
            $organizationId === null => [Level::Platform, Scope::platform()],
            $userId === null => [Level::Organization, Scope::organization($organizationId)],
            default => [Level::User, Scope::member($organizationId, $userId)],
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function own(Level $level, Scope $scope): array
    {
        return array_values((array) ($this->customizations->own(self::KEY, $level, $scope) ?? []));
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     */
    private function index(array $entries, string $key): ?int
    {
        foreach ($entries as $index => $entry) {
            if ($entry['key'] === $key) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private function shaped(array $entry): array
    {
        $shaped = ['key' => (string) $entry['key']];

        foreach (self::FIELDS as $field) {
            $value = $entry[$field] ?? null;
            $shaped[$field] = $value === null || $value === '' ? null
                : (in_array($field, self::DECIMALS, true) ? number_format((float) $value, 2, '.', '') : (string) $value);
        }

        return $shaped;
    }
}
