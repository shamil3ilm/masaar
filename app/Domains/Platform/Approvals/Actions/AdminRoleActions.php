<?php

declare(strict_types=1);

namespace App\Domains\Platform\Approvals\Actions;

use App\Domains\Auth\Enums\AdminPermission;
use App\Domains\Auth\Models\User;
use App\Domains\Auth\Services\AdminRoles;
use App\Domains\Platform\Approvals\ActionResult;
use App\Domains\Platform\Approvals\ApprovableActions;
use App\Domains\Platform\Approvals\Preview;
use DomainException;

/**
 * Custom admin roles: making or changing one from the permission set, and
 * deleting one nobody holds. Changing a role changes the access of everyone
 * who holds it, so it is held like granting access is.
 */
final class AdminRoleActions implements ApprovableActions
{
    public function __construct(
        private readonly AdminRoles $roles,
    ) {}

    public function keys(): array
    {
        return ['admin_roles.save', 'admin_roles.delete'];
    }

    public function label(string $action): string
    {
        return $action === 'admin_roles.save' ? __('Make or change a custom admin role') : __('Delete a custom admin role');
    }

    public function permission(string $action): AdminPermission
    {
        return AdminPermission::AdminsManage;
    }

    public function preview(string $action, array $payload, User $requester): Preview
    {
        $key = (string) ($action === 'admin_roles.save' ? $payload['values']['key'] : $payload['key']);
        $current = $this->roles->all()[$key] ?? null;
        $before = $current === null ? [] : [
            'name' => $current['name'],
            'rank' => $current['rank'],
            'permissions' => array_map(fn (AdminPermission $permission): string => $permission->value, $current['permissions']),
        ];

        if ($action === 'admin_roles.delete') {
            if ($current === null) {
                throw new DomainException(__('There is no such role.'));
            }

            return new Preview('Delete the role '.$current['name'], 'AdminRole', null, $before, []);
        }

        $values = (array) $payload['values'];

        return new Preview(($current === null ? 'Make the role ' : 'Change the role ').$values['name'], 'AdminRole', null, $before, [
            'name' => $values['name'],
            'rank' => $values['rank'],
            'permissions' => $values['permissions'],
        ]);
    }

    public function execute(string $action, array $payload, User $requester): ActionResult
    {
        if ($action === 'admin_roles.delete') {
            $this->roles->delete((string) $payload['key']);

            return new ActionResult(__('Role deleted.'));
        }

        $this->roles->save((array) $payload['values'], (string) $requester->id);

        return new ActionResult(__('Role saved. Everyone who holds it has its permissions from their next request.'));
    }
}
