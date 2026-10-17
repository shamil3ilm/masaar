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
                AdminPermission::FeaturesManage,
                AdminPermission::LicencesManage,
                AdminPermission::BillingManage,
                AdminPermission::UsersManage,
                AdminPermission::UsersTwoFactor,
                AdminPermission::SupportManage,
                AdminPermission::OperationsRun,
            ],
            self::Support => [
                AdminPermission::View,
                AdminPermission::UsersTwoFactor,
                AdminPermission::SupportManage,
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

    /**
     * Seniority, for acting on another admin's access: resetting their second
     * factor, issuing their enrolment link, suspending them. Only an owner, or
     * an admin of a higher rank, may - otherwise a support admin could reset
     * an owner's second factor, enrol their own authenticator on it, and with
     * the owner's password become one.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Owner => 4,
            self::Operations => 3,
            self::Support => 2,
            self::Auditor => 1,
        };
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
