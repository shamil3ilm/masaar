<?php

declare(strict_types=1);

namespace App\Http\Listing;

use Illuminate\Http\Request;

/**
 * Reads a list for the person asking: the filters, the order and the page
 * size from the query string, their saved views on it, and where its CSV
 * and its saved views go.
 *
 * Two surfaces, each with its own routes for saving a view: the portal,
 * where a view belongs to the organization being worked in, and the
 * console, where it is the operator's own ('admin') and saved under My
 * account, as a preference rather than a console action.
 */
final class Lists
{
    public const ADMIN = 'admin';

    public function __construct(
        private readonly SavedViewStore $views,
    ) {}

    /**
     * A portal list, its saved views kept per organization.
     *
     * @param  string|null  $export  the route of the list's CSV
     */
    public function portal(ListSpec $spec, Request $request, string $organizationId, ?string $export = null): ListState
    {
        return $this->read($spec, $request, $organizationId, 'portal', $export);
    }

    /** A console list, its saved views the operator's own. */
    public function admin(ListSpec $spec, Request $request, ?string $export = null): ListState
    {
        return $this->read($spec, $request, self::ADMIN, 'account', $export);
    }

    private function read(ListSpec $spec, Request $request, string $scope, string $surface, ?string $export): ListState
    {
        $state = ListState::read($spec, $request);
        $userId = $request->user()?->getAuthIdentifier();
        $views = $userId === null ? [] : $this->views->for((string) $userId, $scope, $spec->key);

        return $state->with(
            $views,
            $export === null ? null : route($export),
            route($surface.'.views.store'),
            fn (SavedView $view): string => route($surface.'.views.destroy', $view->id),
        );
    }
}
