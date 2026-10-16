<?php

declare(strict_types=1);

namespace App\Domains\Auth\Enums;

/**
 * One thing a platform admin may do in the console or the admin API.
 *
 * Every admin route names the permission it needs (the admin.can
 * middleware), and AdminRoutePermissionTest fails for one that names none.
 */
enum AdminPermission: string
{
    /** Read the console: companies, users, licences, webhooks, queue, logs. */
    case View = 'console.view';

    case AuditView = 'audit.view';

    /** Approve or turn down a company that signed itself up. */
    case OrganizationsReview = 'organizations.review';

    /** Issue, suspend, revoke and rotate licences; change their limits. */
    case LicencesManage = 'licences.manage';

    /** Suspend and reactivate accounts. */
    case UsersManage = 'users.manage';

    /** Reset someone's two-factor, and issue enrolment links. */
    case UsersTwoFactor = 'users.two_factor';

    /** Replay webhooks, process and retry the queue, reset connectivity. */
    case OperationsRun = 'operations.run';

    /** Platform settings, branding and kill switches. */
    case PlatformConfigure = 'platform.configure';

    /** Grant, change and revoke platform admins. */
    case AdminsManage = 'admins.manage';
}
