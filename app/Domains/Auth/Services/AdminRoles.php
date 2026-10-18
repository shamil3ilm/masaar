<?php

declare(strict_types=1);

namespace App\Domains\Auth\Services;

use App\Domains\Auth\Enums\AdminPermission;
use App\Domains\Auth\Enums\PlatformRole;
use App\Domains\Auth\Models\AdminRole;
use App\Domains\Auth\Models\User;
use DomainException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The platform's admin roles: the four system roles, whose permissions are
 * code's (PlatformRole), and custom roles made from the permission set.
 *
 * Permissions stay code-defined because code checks them (admin.can,
 * User::canAdmin). A custom role is a named set of them with a rank below
 * the owner's - rank is who may act on whose access - and it cannot be
 * deleted while anyone holds it.
 *
 * Asked on every console request, so the whole table is cached as one entry
 * and forgotten on write.
 */
class AdminRoles
{
    private const CACHE_KEY = 'admin.roles';

    /** A custom role ranks below the owner. */
    public const MAX_CUSTOM_RANK = 3;

    /**
     * Every role by key, system roles first.
     *
     * @return array<string, array{key: string, name: string, description: string|null, permissions: list<AdminPermission>, rank: int, system: bool}>
     */
    public function all(): array
    {
        $rows = Cache::rememberForever(self::CACHE_KEY, fn (): array => AdminRole::query()->orderByDesc('system')->orderByDesc('rank')->orderBy('name')->get()
            ->map(fn (AdminRole $role): array => $role->only(['key', 'name', 'description', 'permissions', 'rank', 'system']))
            ->all());

        $roles = [];

        foreach ($rows as $row) {
            $system = $row['system'] ? PlatformRole::tryFrom($row['key']) : null;

            $roles[$row['key']] = [
                ...$row,
                'permissions' => $system?->permissions()
                    ?? array_values(array_filter(array_map(fn (string $permission): ?AdminPermission => AdminPermission::tryFrom($permission), (array) $row['permissions']))),
            ];
        }

        return $roles;
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->all());
    }

    public function exists(string $key): bool
    {
        return array_key_exists($key, $this->all());
    }

    public function allows(?string $key, AdminPermission $permission): bool
    {
        return $key !== null && in_array($permission, $this->all()[$key]['permissions'] ?? [], true);
    }

    public function rank(?string $key): int
    {
        return $key === null ? 0 : ($this->all()[$key]['rank'] ?? 0);
    }

    public function name(?string $key): string
    {
        return $key === null ? '' : ($this->all()[$key]['name'] ?? $key);
    }

    /**
     * How many admins hold each role.
     *
     * @return array<string, int>
     */
    public function holders(): array
    {
        return User::query()->where('is_platform_admin', true)->get(['platform_role'])
            ->countBy(fn (User $user): string => (string) ($user->platform_role ?? PlatformRole::Owner->value))
            ->all();
    }

    /**
     * The values a role form posted, checked: the roles page and the approval
     * that later applies them read the same thing.
     *
     * @param  array<string, mixed>  $input
     * @return array{key: string, name: string, description: string|null, permissions: list<string>, rank: int}
     *
     * @throws ValidationException
     * @throws DomainException for a system role
     */
    public function clean(?string $key, array $input): array
    {
        if ($key !== null && ($this->all()[$key]['system'] ?? false)) {
            throw new DomainException(__('A system role is defined by the platform and cannot be changed.'));
        }

        $name = trim((string) ($input['name'] ?? ''));
        $permissions = array_values(array_unique(array_map('strval', (array) ($input['permissions'] ?? []))));
        $rank = filter_var($input['rank'] ?? null, FILTER_VALIDATE_INT);
        $editing = $key !== null;
        $key ??= Str::limit(Str::slug($name, '_'), 20, '');
        $problems = [];

        if ($name === '' || mb_strlen($name) > 100) {
            $problems['name'] = __('Give the role a name of at most 100 characters.');
        } elseif ($key === '' || (! $editing && $this->exists($key))) {
            $problems['name'] = __('A role with this name already exists.');
        }

        if ($permissions === [] || array_diff($permissions, array_map(fn (AdminPermission $p): string => $p->value, AdminPermission::cases())) !== []) {
            $problems['permissions'] = __('Choose at least one permission.');
        } elseif (in_array(AdminPermission::AdminsManage->value, $permissions, true)) {
            // Managing admins is the owner's, and the platform has one owner:
            // no custom role is a second.
            $problems['permissions'] = __('Managing admins is the owner\'s alone. A custom role cannot hold it.');
        }

        if ($rank === false || $rank < 1 || $rank > self::MAX_CUSTOM_RANK) {
            $problems['rank'] = __('A rank from 1 to :max: only the owner is above.', ['max' => self::MAX_CUSTOM_RANK]);
        }

        if ($problems !== []) {
            throw ValidationException::withMessages($problems);
        }

        return [
            'key' => $key,
            'name' => $name,
            'description' => ($description = trim((string) ($input['description'] ?? ''))) === '' ? null : mb_substr($description, 0, 500),
            'permissions' => $permissions,
            'rank' => (int) $rank,
        ];
    }

    /**
     * @param  array{key: string, name: string, description: string|null, permissions: list<string>, rank: int}  $values
     */
    public function save(array $values, ?string $actorId): void
    {
        if ($this->all()[$values['key']]['system'] ?? false) {
            throw new DomainException(__('A system role is defined by the platform and cannot be changed.'));
        }

        DB::transaction(function () use ($values, $actorId): void {
            // A role of a name deleted before comes back as the same row: the
            // key stays unique.
            $role = AdminRole::withTrashed()->where('key', $values['key'])->first() ?? new AdminRole;

            if ($role->trashed()) {
                $role->restore();
            }

            $role->fill([...$values, 'system' => false]);
            $role->forceFill(['updated_by' => $actorId, 'created_by' => $role->created_by ?? $actorId, 'deleted_by' => null])->save();
        });

        $this->forget();
    }

    /**
     * @throws DomainException for a system role, or one someone holds
     */
    public function delete(string $key): void
    {
        $role = $this->all()[$key] ?? throw new DomainException(__('There is no such role.'));

        if ($role['system']) {
            throw new DomainException(__('A system role is defined by the platform and cannot be changed.'));
        }

        if (($this->holders()[$key] ?? 0) > 0) {
            throw new DomainException(__('Admins hold this role. Give them another one first.'));
        }

        DB::transaction(fn () => AdminRole::query()->where('key', $key)->get()->each->delete());

        $this->forget();
    }

    private function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
        DB::afterCommit(fn () => Cache::forget(self::CACHE_KEY));
    }
}
