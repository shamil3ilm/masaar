<?php

declare(strict_types=1);

namespace App\Domains\Platform\Access;

use App\Domains\Auth\Models\User;
use App\Domains\Organization\Services\TenantResolver;
use Illuminate\Contracts\Auth\Guard;

/**
 * Access for the portal page being drawn: its organization and the person
 * signed in. Behind @canuse('issue_invoices') ... @endcanuse, so a page
 * hides an action the person cannot take rather than offering a button that
 * only refuses.
 */
class PortalAccess
{
    public function __construct(
        private readonly Access $access,
        private readonly TenantResolver $tenant,
        private readonly Guard $auth,
    ) {}

    /** Null outside an organization's page. */
    public function decision(Feature|string $feature): ?Decision
    {
        $organization = $this->tenant->getOrganization();
        $user = $this->auth->user();

        if ($organization === null || ! $user instanceof User) {
            return null;
        }

        return $this->access->decide($organization, $user, $feature instanceof Feature ? $feature : Feature::from($feature));
    }

    public function allows(Feature|string $feature): bool
    {
        return $this->decision($feature)?->allowed ?? false;
    }
}
