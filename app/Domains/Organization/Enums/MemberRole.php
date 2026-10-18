<?php

declare(strict_types=1);

namespace App\Domains\Organization\Enums;

/**
 * A person's role in one organization (organization_user.role).
 *
 * Admin runs the organization; member invoices; viewer reads.
 */
enum MemberRole: string
{
    case Admin = 'admin';
    case Member = 'member';
    case Viewer = 'viewer';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $role): string => $role->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::Admin => __('Admin'),
            self::Member => __('Member'),
            self::Viewer => __('Viewer'),
        };
    }
}
