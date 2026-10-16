<?php

declare(strict_types=1);

namespace App\Domains\Organization\Http\Concerns;

use App\Domains\Organization\Http\Middleware\PortalTenant;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Services\TenantResolver;
use Illuminate\Http\Request;

/**
 * The organization a portal request works in, as PortalTenant resolved it.
 *
 * A session with no organization selected is a 404. Given a refusal, the
 * signed-in user must also administer it, or it is a 403 with that message.
 */
trait ResolvesPortalOrganization
{
    protected function portalOrganizationId(Request $request, ?string $adminOnly = null): string
    {
        $organizationId = $request->attributes->get(PortalTenant::ORG_ID);

        abort_unless(is_string($organizationId), 404);

        if ($adminOnly !== null) {
            abort_unless($request->user()?->roleIn($organizationId) === 'admin', 403, $adminOnly);
        }

        return $organizationId;
    }

    protected function portalOrganization(TenantResolver $tenant, Request $request, ?string $adminOnly = null): Organization
    {
        $this->portalOrganizationId($request, $adminOnly);

        return $tenant->getOrganization() ?? abort(404);
    }
}
