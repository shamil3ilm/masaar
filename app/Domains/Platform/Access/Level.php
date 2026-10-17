<?php

declare(strict_types=1);

namespace App\Domains\Platform\Access;

/**
 * Where an answer came from, in the order Access asks: the first level that
 * says no decides.
 */
enum Level: string
{
    /** A platform-wide kill switch. */
    case Platform = 'platform';

    /** The organization's country: switched off, or the feature is not part of it. */
    case Country = 'country';

    /** The licence: its tier's features, its scopes, or having none. */
    case Plan = 'plan';

    /** The organization: its review status, its own kill switch, an admin's override. */
    case Organization = 'organization';

    /** The person's role in the organization. */
    case Role = 'role';

    public function label(): string
    {
        return match ($this) {
            self::Platform => __('Platform'),
            self::Country => __('Country'),
            self::Plan => __('Plan'),
            self::Organization => __('Organization'),
            self::Role => __('Role'),
        };
    }
}
