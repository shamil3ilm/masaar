<?php

declare(strict_types=1);

namespace App\Domains\Organization\Enums;

/**
 * A person's role in one organization (organization_user.role).
 *
 * Owner runs the organization and decides who else does; admin runs it;
 * member invoices; accountant reads, reports and exports, the plan's
 * invoices included; viewer reads. An organization always keeps an owner or
 * an admin.
 */
enum MemberRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Member = 'member';
    case Accountant = 'accountant';
    case Viewer = 'viewer';

    /** The roles that run the organization: its settings, its people. */
    public const ADMINISTERING = ['owner', 'admin'];

    /** The roles that read and change nothing. */
    public const READ_ONLY = ['accountant', 'viewer'];

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $role): string => $role->value, self::cases());
    }

    public static function administers(?string $role): bool
    {
        return in_array($role, self::ADMINISTERING, true);
    }

    public static function readOnly(?string $role): bool
    {
        return in_array($role, self::READ_ONLY, true);
    }

    /** Least to most, for a feature's smallest role (Access). */
    public static function rank(?string $role): int
    {
        return match ($role) {
            'owner' => 4,
            'admin' => 3,
            'member' => 2,
            'accountant', 'viewer' => 1,
            default => 0,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Owner => __('Owner'),
            self::Admin => __('Admin'),
            self::Member => __('Member'),
            self::Accountant => __('Accountant'),
            self::Viewer => __('Viewer'),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Owner => __('Everything an admin does, and decides who are owners.'),
            self::Admin => __('Company details, numbering, the authority connection and the team.'),
            self::Member => __('Prepares and issues invoices.'),
            self::Accountant => __('Reads everything, runs reports and exports, and sees the plan\'s invoices. Changes nothing.'),
            self::Viewer => __('Reads everything and changes nothing.'),
        };
    }
}
