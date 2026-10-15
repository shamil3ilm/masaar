<?php

declare(strict_types=1);

namespace App\Domains\Branding\Http\Controllers;

use App\Domains\Organization\Http\Middleware\PortalTenant;
use Illuminate\Http\Request;

/**
 * An organization's own look, over the platform's: its portal and the
 * invoices it prints.
 *
 * Its administrators only. The organization is the one the session is
 * working in, never one named in the request.
 */
class PortalBrandingController extends BrandingController
{
    protected function owner(Request $request): ?string
    {
        $organizationId = $request->attributes->get(PortalTenant::ORG_ID);

        abort_unless(is_string($organizationId), 404);
        abort_unless($request->user()?->roleIn($organizationId) === 'admin', 403, 'Only an administrator of this organization can change its branding.');

        return $organizationId;
    }

    protected function view(): string
    {
        return 'portal.branding';
    }

    protected function route(): string
    {
        return 'portal.branding';
    }
}
