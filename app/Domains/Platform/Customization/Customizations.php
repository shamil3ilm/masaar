<?php

declare(strict_types=1);

namespace App\Domains\Platform\Customization;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Compliance\Engine\Services\JurisdictionResolver;
use App\Domains\Organization\Models\Organization;
use DomainException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Every customization's value, by level: the shipped default, then the
 * platform's, the organization's, the branch's, the till's and the person's, the nearest
 * winning unless a level above locked its own (docs/architecture/CUSTOMIZATION.md).
 *
 * The store is one table, a row per key per holder - 'platform',
 * 'organization:{id}', 'branch:{id}', 'till:{id}', 'member:{org}:{user}' or 'user:{id}' -
 * with no live row meaning "as the level above", so resetting soft-deletes
 * it, with who did, and setting it again brings the row back. Each row
 * records who made it and who last changed it. A
 * definition may keep one level in an existing column instead (Definition
 * $columns); null there means the same.
 *
 * Each holder's rows are cached as one entry and forgotten when one of them
 * changes, again after the write commits so a read between the two cannot
 * put the old value back for good. Column-kept levels are read from their
 * row each time: other code writes those columns too, and a cache it does
 * not know about would be stale.
 */
class Customizations
{
    private const CACHE_KEY = 'customizations:';

    public function __construct(
        private readonly Catalogue $catalogue,
        private readonly AuditService $audit,
        private readonly JurisdictionResolver $jurisdictions,
    ) {}

    public function definition(string $key): Definition
    {
        return $this->catalogue->get($key);
    }

    public function get(string $key, Scope $scope): mixed
    {
        return $this->resolve($key, $scope)->value;
    }

    /**
     * The value for a scope, where it came from, and what each level sets.
     *
     * @param  array<string, mixed>  $known  level value => a column-kept level's value the caller already holds
     */
    public function resolve(string $key, Scope $scope, array $known = []): Resolved
    {
        $definition = $this->definition($key);
        $value = $definition->default;
        $source = null;
        $lockedBy = null;
        $at = Level::Platform;
        $levels = [];

        foreach ($definition->levels as $level) {
            // The organization's business type suggests a value, between the
            // platform's and the organization's own (Definition $preset).
            if ($level === Level::Organization && $definition->preset !== null && $lockedBy === null && $scope->organizationId !== null) {
                $value = $this->preset($definition, $scope->organizationId) ?? $value;
            }

            if (! $scope->has($level) || $scope->holder($level, $definition->acrossOrganizations) === null) {
                continue;
            }

            $at = $level;
            $row = array_key_exists($level->value, $known) && $definition->column($level) !== null
                ? ($known[$level->value] === null ? null : ['value' => $this->cast($definition, $known[$level->value]), 'locked' => false])
                : $this->read($definition, $level, $scope);

            if ($row === null) {
                continue;
            }

            $levels[$level->value] = $row['value'];

            // A lock above holds: what a lower level set is kept, and shown,
            // but does not apply.
            if ($lockedBy !== null) {
                continue;
            }

            $value = $row['value'];
            $source = $level;

            if ($definition->lockable && $row['locked']) {
                $lockedBy = $level;
            }
        }

        if ($definition->type === 'entries') {
            $value = $this->merge([(array) $definition->default, ...array_map(fn (mixed $list): array => (array) $list, array_values($levels))]);
        }

        return new Resolved($definition, $value, $source, $at, $levels[$at->value] ?? null, $lockedBy, $levels);
    }

    /**
     * What the organization's business type sets for a customization, or
     * null when it has no type or the type leaves it alone. The types are a
     * customization too, so an organization's own list counts.
     */
    private function preset(Definition $definition, string $organizationId): mixed
    {
        $type = DB::table('organizations')->where('id', $organizationId)->value('business_type');

        if (! is_string($type) || $type === '') {
            return null;
        }

        foreach ((array) $this->get(Catalogue::BUSINESS_TYPES, Scope::organization($organizationId)) as $entry) {
            if (($entry['key'] ?? null) === $type) {
                $raw = $entry[$definition->preset] ?? null;

                return $raw === null || $raw === '' ? null : $definition->parse($raw);
            }
        }

        return null;
    }

    /** What one level itself sets, or null when it inherits. */
    public function own(string $key, Level $level, Scope $scope): mixed
    {
        $definition = $this->definition($key);

        return $scope->has($level) ? $this->read($definition, $level, $scope)['value'] ?? null : null;
    }

    /**
     * Set a level's value, checked against the definition, and record it.
     *
     * @throws ValidationException when the value is not one it can hold
     * @throws DomainException when a level above locked it
     */
    public function set(string $key, Level $level, Scope $scope, mixed $raw, ?string $actorId, bool $locked = false): void
    {
        $definition = $this->definition($key);
        $holder = $this->holder($definition, $level, $scope);
        $value = $definition->parse($raw);

        if ($value === null) {
            throw ValidationException::withMessages([$key => __(':setting is not a valid value.', ['setting' => $definition->label])]);
        }

        if ($definition->type === 'entries') {
            $value = $this->entries($definition, $value);
        }

        $this->assertNotLockedAbove($definition, $level, $scope);

        $this->write($definition, $level, $scope, $holder, $value, $definition->lockable && $locked, $actorId);
    }

    /**
     * Back to what the level above says.
     *
     * @throws DomainException when a level above locked it
     */
    public function reset(string $key, Level $level, Scope $scope, ?string $actorId): void
    {
        $definition = $this->definition($key);
        $holder = $this->holder($definition, $level, $scope);

        $this->assertNotLockedAbove($definition, $level, $scope);

        $this->write($definition, $level, $scope, $holder, null, false, $actorId);
    }

    /**
     * Every customization a level can set, as it stands for the scope: what
     * a settings page shows - the value, where it comes from, and whether
     * the level has its own to reset. Lists of entries have screens of their
     * own and are left out.
     *
     * @return list<Resolved>
     */
    public function describe(Level $level, Scope $scope): array
    {
        $rows = [];

        foreach ($this->catalogue->all() as $definition) {
            if (! $definition->allows($level) || $definition->type === 'entries' || $definition->ownScreen || ! $scope->has($level)) {
                continue;
            }

            // A console preference is not the portal's to show.
            if ($definition->console && $scope->organizationId !== null) {
                continue;
            }

            // Nor one the organization's country does not use.
            if ($definition->country && $scope->organizationId !== null && ! $this->countryUses($definition->key, $scope->organizationId)) {
                continue;
            }

            $resolved = $this->resolve($definition->key, $this->trim($scope, $level, $definition));

            if ($resolved->at === $level) {
                $rows[] = $resolved;
            }
        }

        return $rows;
    }

    private function countryUses(string $key, string $organizationId): bool
    {
        return $this->jurisdictions->forRules(Organization::query()->find($organizationId))->presentation()->usesCustomization($key);
    }

    /**
     * The scope cut down to a level, so a page about the organization does
     * not resolve the branch or the person below it.
     */
    private function trim(Scope $scope, Level $level, Definition $definition): Scope
    {
        return match ($level) {
            Level::Platform => Scope::platform(),
            Level::Organization => Scope::organization((string) $scope->organizationId),
            Level::Branch => Scope::branch((string) $scope->organizationId, (string) $scope->branchId),
            Level::Till => Scope::till((string) $scope->organizationId, $scope->branchId, (string) $scope->tillId),
            Level::User => $definition->acrossOrganizations || $scope->organizationId === null
                ? Scope::person((string) $scope->userId)
                : Scope::member($scope->organizationId, (string) $scope->userId),
        };
    }

    private function holder(Definition $definition, Level $level, Scope $scope): string
    {
        if (! $definition->allows($level)) {
            throw new InvalidArgumentException("{$definition->key} cannot be set for a {$level->value}.");
        }

        return $scope->holder($level, $definition->acrossOrganizations)
            ?? throw new InvalidArgumentException("The scope does not name a {$level->value}.");
    }

    private function assertNotLockedAbove(Definition $definition, Level $level, Scope $scope): void
    {
        if (! $definition->lockable) {
            return;
        }

        foreach ($definition->levels as $above) {
            if ($above->depth() >= $level->depth()) {
                break;
            }

            if ($scope->has($above) && ($this->read($definition, $above, $scope)['locked'] ?? false)) {
                throw new DomainException(__(':setting is locked by: :level', ['setting' => $definition->label, 'level' => $above->label()]));
            }
        }
    }

    /**
     * @return array{value: mixed, locked: bool}|null
     */
    private function read(Definition $definition, Level $level, Scope $scope): ?array
    {
        $holder = $scope->holder($level, $definition->acrossOrganizations);

        if ($holder === null) {
            return null;
        }

        $column = $definition->column($level);

        if ($column !== null) {
            $raw = DB::table($column[0])->where('id', $this->rowId($level, $scope))->value($column[1]);

            return $raw === null ? null : ['value' => $this->cast($definition, $raw), 'locked' => false];
        }

        return $this->rows($holder)[$definition->key] ?? null;
    }

    /**
     * One holder's stored values, by key.
     *
     * @return array<string, array{value: mixed, locked: bool}>
     */
    private function rows(string $holder): array
    {
        return Cache::rememberForever(self::CACHE_KEY.$holder, function () use ($holder): array {
            $rows = [];

            foreach (DB::table('customizations')->where('holder', $holder)->whereNull('deleted_at')->get(['key', 'value', 'locked']) as $row) {
                $rows[$row->key] = ['value' => json_decode((string) $row->value, true), 'locked' => (bool) $row->locked];
            }

            return $rows;
        });
    }

    private function write(Definition $definition, Level $level, Scope $scope, string $holder, mixed $value, bool $locked, ?string $actorId): void
    {
        $column = $definition->column($level);

        DB::transaction(function () use ($definition, $level, $scope, $holder, $value, $locked, $actorId, $column): void {
            $before = $this->read($definition, $level, $scope)['value'] ?? null;

            if ($column !== null) {
                DB::table($column[0])->where('id', $this->rowId($level, $scope))->update([$column[1] => $value, 'updated_at' => now()]);
            } elseif ($value === null) {
                // Reset is a removal, kept with who made it; setting the
                // value again brings the same row back (store()).
                DB::table('customizations')->where('key', $definition->key)->where('holder', $holder)->whereNull('deleted_at')
                    ->update(['deleted_at' => now(), 'deleted_by' => $actorId]);
            } else {
                $this->store($definition, $level, $scope, $holder, $value, $locked, $actorId);
            }

            $this->record($definition, $level, $holder, $before, $value, $locked, $actorId);
        });

        Cache::forget(self::CACHE_KEY.$holder);
        DB::afterCommit(fn () => Cache::forget(self::CACHE_KEY.$holder));
    }

    private function store(Definition $definition, Level $level, Scope $scope, string $holder, mixed $value, bool $locked, ?string $actorId): void
    {
        $existing = DB::table('customizations')->where('key', $definition->key)->where('holder', $holder)->lockForUpdate()->value('id');
        $row = [
            'value' => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'locked' => $locked,
            'updated_by' => $actorId,
            'updated_at' => now(),
            'deleted_at' => null,
            'deleted_by' => null,
        ];

        if ($existing !== null) {
            DB::table('customizations')->where('id', $existing)->update($row);

            return;
        }

        DB::table('customizations')->insert([
            ...$row,
            'id' => (string) Str::uuid(),
            'key' => $definition->key,
            'level' => $level->value,
            'holder' => $holder,
            'org_id' => $level === Level::Platform || ($level === Level::User && $definition->acrossOrganizations) ? null : $scope->organizationId,
            // A till's row names its branch, so it goes with the branch.
            'branch_id' => in_array($level, [Level::Branch, Level::Till], true) ? $scope->branchId : null,
            'user_id' => $level === Level::User ? $scope->userId : null,
            'created_by' => $actorId,
            'created_at' => now(),
        ]);
    }

    private function record(Definition $definition, Level $level, string $holder, mixed $before, mixed $after, bool $locked, ?string $actorId): void
    {
        $detail = ['key' => $definition->key, 'level' => $level->value, 'holder' => $holder, 'locked' => $locked];

        match ($definition->audit) {
            Definition::AUDIT_SECURITY => $this->audit->logSecurity('customization.changed', 'Customization', null,
                [...$detail, 'before' => $before, 'after' => $after], userId: $actorId),
            Definition::AUDIT_RECORD => $this->audit->logChange('customization.changed', 'Customization', null,
                ['value' => $before], ['value' => $after], $detail, $actorId),
            default => null,
        };
    }

    /** The row a column-kept level lives on. */
    private function rowId(Level $level, Scope $scope): ?string
    {
        return match ($level) {
            Level::Organization => $scope->organizationId,
            Level::Branch => $scope->branchId,
            Level::Till => $scope->tillId,
            Level::User => $scope->userId,
            Level::Platform => null,
        };
    }

    private function cast(Definition $definition, mixed $raw): mixed
    {
        return match ($definition->type) {
            'bool' => (bool) $raw,
            'int' => (int) $raw,
            'choice' => (string) $raw,
            default => $raw,
        };
    }

    /**
     * A level's own list of entries, each named by its key and carrying only
     * the definition's fields. A hidden entry covers one from above.
     *
     * @param  list<mixed>  $entries
     * @return list<array<string, mixed>>
     */
    private function entries(Definition $definition, array $entries): array
    {
        $clean = [];

        foreach ($entries as $entry) {
            if (! is_array($entry) || ! is_string($entry['key'] ?? null) || $entry['key'] === '') {
                throw ValidationException::withMessages([$definition->key => __(':setting is not a valid value.', ['setting' => $definition->label])]);
            }

            $clean[] = ! empty($entry['hidden'])
                ? ['key' => $entry['key'], 'hidden' => true]
                : ['key' => $entry['key'], ...array_intersect_key($entry, array_flip($definition->fields))];
        }

        return $clean;
    }

    /**
     * Each level's entries laid over those above, by key: a changed entry
     * keeps its place, a new one goes after, a hidden one goes.
     *
     * @param  list<array<int, mixed>>  $layers  broadest first
     * @return list<array<string, mixed>>
     */
    private function merge(array $layers): array
    {
        $merged = [];

        foreach ($layers as $list) {
            foreach ($list as $entry) {
                $merged[$entry['key']] = ! empty($entry['hidden']) ? null : $entry;
            }
        }

        return array_values(array_filter($merged));
    }
}
