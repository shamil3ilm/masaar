<?php

declare(strict_types=1);

namespace App\Domains\Platform\Customization;

/**
 * Who a customization's value belongs to, broadest first. A value at a lower
 * level overrides the one above it, unless the one above is locked.
 */
enum Level: string
{
    /** The platform's default, set in the admin console. */
    case Platform = 'platform';

    /** One organization's, set by its administrators. */
    case Organization = 'organization';

    /** One branch of an organization: the till's printer, for one. */
    case Branch = 'branch';

    /** One person's own: in one organization, or across all of them. */
    case User = 'user';

    public function label(): string
    {
        return match ($this) {
            self::Platform => __('Platform'),
            self::Organization => __('Organization'),
            self::Branch => __('Branch'),
            self::User => __('Your own'),
        };
    }

    /** Broadest first: the order values are resolved in. */
    public function depth(): int
    {
        return match ($this) {
            self::Platform => 0,
            self::Organization => 1,
            self::Branch => 2,
            self::User => 3,
        };
    }
}
