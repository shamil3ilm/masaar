<?php

declare(strict_types=1);

namespace App\Domains\Auth\Enums;

/**
 * What a platform admin is for, and so what they may do.
 */
enum PlatformRole: string
{
    /** Everything, including managing admins and the platform itself. */
    case Owner = 'owner';

    /** Runs the service: reviews companies, licences, accounts, the queue. */
    case Operations = 'operations';

    /** Helps people: reads everything, and resets a lost second factor. */
    case Support = 'support';

    /** Checks the record: reads everything, including the audit log. */
    case Auditor = 'auditor';

    /**
     * @return list<AdminPermission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Owner => AdminPermission::cases(),
            self::Operations => [
                AdminPermission::View,
                AdminPermission::AuditView,
                AdminPermission::OrganizationsReview,
                AdminPermission::LicencesManage,
                AdminPermission::UsersManage,
                AdminPermission::UsersTwoFactor,
                AdminPermission::OperationsRun,
            ],
            self::Support => [
                AdminPermission::View,
                AdminPermission::UsersTwoFactor,
            ],
            self::Auditor => [
                AdminPermission::View,
                AdminPermission::AuditView,
            ],
        };
    }

    public function allows(AdminPermission $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
