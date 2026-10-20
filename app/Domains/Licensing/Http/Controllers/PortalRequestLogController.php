<?php

declare(strict_types=1);

namespace App\Domains\Licensing\Http\Controllers;

use App\Domains\Licensing\Services\RequestLog;
use App\Domains\Organization\Http\Concerns\ResolvesPortalOrganization;
use App\Domains\Organization\Services\TenantResolver;
use App\Http\Controllers\Controller;
use App\Http\Listing\Lists;
use App\Support\CountryClock;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Portal › Integrations › Request log: the organization's recent partner
 * API requests - method, path, answer, key, time, request id and error -
 * searched with the list toolkit. Its administrators only; nothing there is
 * a body or a secret.
 */
class PortalRequestLogController extends Controller
{
    use ResolvesPortalOrganization;

    public function __construct(
        private readonly TenantResolver $tenant,
        private readonly RequestLog $requests,
        private readonly Lists $lists,
    ) {}

    public function index(Request $request): View
    {
        $organization = $this->portalOrganization($this->tenant, $request, __('Only an administrator of this organization can manage its integrations.'));
        $organizationId = (string) $organization->id;
        $keys = $this->requests->keys($organizationId);
        $list = $this->lists->portal($this->requests->spec(CountryClock::timeZone($organization->country), $keys), $request, $organizationId);

        return view('portal.integrations.requests', [
            'organization' => $organization,
            'requests' => $this->requests->page($organizationId, $list),
            'list' => $list,
            'keys' => $keys,
            'days' => $this->requests->retentionDays($organizationId),
        ]);
    }
}
