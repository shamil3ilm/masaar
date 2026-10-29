<?php

declare(strict_types=1);

namespace App\Domains\Organization\Attributes;

use Attribute;

/**
 * A command or queued job whose work spans every organization - platform
 * maintenance, a report across tenants - and so runs without declaring one.
 *
 * Any other command of the application's own, and any queued job, is refused
 * a tenant-scoped query until it declares the organization it acts for
 * (TenantResolver::runAs()). The reason is required, and the classes that
 * carry this are counted (TenantDeclarationTest), so the list is seen to
 * change.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class RunsAcrossTenants
{
    public function __construct(
        public readonly string $reason,
    ) {}
}
