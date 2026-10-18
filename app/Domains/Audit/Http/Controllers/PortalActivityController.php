<?php

declare(strict_types=1);

namespace App\Domains\Audit\Http\Controllers;

use App\Domains\Audit\Services\ActivityLog;
use App\Domains\Organization\Http\Concerns\ResolvesPortalOrganization;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Services\Membership;
use App\Domains\Organization\Services\TenantResolver;
use App\Http\Controllers\Controller;
use App\Http\Listing\Lists;
use App\Http\Listing\ListState;
use App\Support\CountryClock;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The organization's activity: who issued, corrected and changed what, and
 * who invited whom, with the same filters as every history list.
 *
 * Its administrators only - the owners: a cashier's own history is the
 * invoice list narrowed to them.
 */
class PortalActivityController extends Controller
{
    use ResolvesPortalOrganization;

    public function __construct(
        private readonly TenantResolver $tenant,
        private readonly ActivityLog $activity,
        private readonly Membership $membership,
        private readonly Lists $lists,
    ) {}

    public function index(Request $request): View
    {
        $organization = $this->organization($request);
        $list = $this->list($request, $organization, 'portal.activity.export');

        return view('portal.activity', [
            'organization' => $organization,
            'entries' => $this->activity->page((string) $organization->id, $list),
            'list' => $list,
        ]);
    }

    /** Exactly the entries the list's filters give, as a CSV. */
    public function export(Request $request): StreamedResponse
    {
        $organization = $this->organization($request);

        return $this->activity->csv((string) $organization->id, $this->list($request, $organization), 'activity-'.now()->format('Y-m-d').'.csv');
    }

    private function list(Request $request, Organization $organization, ?string $export = null): ListState
    {
        $people = $this->membership->members((string) $organization->id)
            ->mapWithKeys(fn ($user): array => [(string) $user->id => (string) ($user->name ?: $user->email)])
            ->all();

        return $this->lists->portal(
            $this->activity->spec('portal.activity', CountryClock::timeZone($organization->country), $people),
            $request,
            (string) $organization->id,
            $export,
        );
    }

    private function organization(Request $request): Organization
    {
        return $this->portalOrganization($this->tenant, $request, __('Only an administrator of this organization can see its activity.'));
    }
}
