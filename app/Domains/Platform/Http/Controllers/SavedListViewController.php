<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Organization\Http\Concerns\ResolvesPortalOrganization;
use App\Http\Controllers\Controller;
use App\Http\Listing\Lists;
use App\Http\Listing\SavedViewStore;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Naming the filters on a list, and forgetting them again.
 *
 * A view is the person's own: in the portal it is kept for the organization
 * being worked in, in the console for the operator. Only a list that offers
 * views can be named, and the query is kept as the list's own links write
 * it - scalars only - so a saved view cannot carry anything the list would
 * not have understood. The list checks it again whenever it is opened.
 *
 * A personal preference, so a viewer may keep views too: the portal's routes
 * sit outside org.writer. An operator's are under My account rather than
 * the console, since keeping one changes nothing on the platform and needs
 * no admin permission - but only a platform admin keeps them.
 */
class SavedListViewController extends Controller
{
    use ResolvesPortalOrganization;

    /** The portal's lists that offer saved views, by route name. */
    public const PORTAL_LISTS = [
        'portal.invoices.index', 'portal.notes.index', 'portal.submissions', 'portal.customers.index', 'portal.billing',
    ];

    /** The console's lists that offer saved views, by route name. */
    public const ADMIN_LISTS = ['admin.organizations', 'admin.audit', 'admin.licences.index', 'admin.queue'];

    /** Longer than any filter set the lists can produce. */
    private const MAX_QUERY = 2000;

    public function __construct(
        private readonly SavedViewStore $views,
    ) {}

    public function portalStore(Request $request): RedirectResponse
    {
        return $this->store($request, $this->portalOrganizationId($request), self::PORTAL_LISTS);
    }

    public function portalDestroy(Request $request, string $viewId): RedirectResponse
    {
        return $this->destroy($request, $this->portalOrganizationId($request), $viewId);
    }

    public function adminStore(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->isPlatformAdmin() === true, 403);

        return $this->store($request, Lists::ADMIN, self::ADMIN_LISTS);
    }

    public function adminDestroy(Request $request, string $viewId): RedirectResponse
    {
        abort_unless($request->user()?->isPlatformAdmin() === true, 403);

        return $this->destroy($request, Lists::ADMIN, $viewId);
    }

    /**
     * @param  list<string>  $lists
     */
    private function store(Request $request, string $scope, array $lists): RedirectResponse
    {
        $validated = $request->validate([
            'list' => ['required', 'string', Rule::in($lists)],
            'name' => ['required', 'string', 'max:60'],
            'query' => ['nullable', 'string', 'max:'.self::MAX_QUERY],
        ]);

        $query = $this->scalars((string) ($validated['query'] ?? ''));
        $back = route($validated['list']).($query === '' ? '' : '?'.$query);

        try {
            $this->views->save((string) $request->user()?->getAuthIdentifier(), $scope, $validated['list'], trim($validated['name']), $query);
        } catch (DomainException $e) {
            return redirect()->to($back)->with('error', $e->getMessage());
        }

        return redirect()->to($back)->with('success', __('View saved.'));
    }

    private function destroy(Request $request, string $scope, string $viewId): RedirectResponse
    {
        $deleted = $this->views->delete((string) $request->user()?->getAuthIdentifier(), $scope, $viewId);

        return back()->with($deleted ? 'success' : 'error', $deleted ? __('View deleted.') : __('That view is not one of yours.'));
    }

    /** The query string with anything that is not a plain value taken out. */
    private function scalars(string $query): string
    {
        parse_str(ltrim($query, '?'), $values);

        $values = array_filter($values, fn (mixed $value, mixed $key): bool => is_string($key) && is_string($value) && $value !== '' && $key !== 'page', ARRAY_FILTER_USE_BOTH);

        return http_build_query($values, '', '&', PHP_QUERY_RFC3986);
    }
}
