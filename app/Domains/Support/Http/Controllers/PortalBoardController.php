<?php

declare(strict_types=1);

namespace App\Domains\Support\Http\Controllers;

use App\Domains\Organization\Http\Concerns\ResolvesPortalOrganization;
use App\Domains\Organization\Services\TenantResolver;
use App\Domains\Support\Models\FeatureBoardItem;
use App\Domains\Support\Services\FeatureBoard;
use App\Domains\Support\Services\RequestCategories;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The feature board in the portal: what customers asked for, as an admin
 * published it, to back ("we need this too") and to follow.
 *
 * Each item shows the admin's words, its stage and notes, and how many
 * companies want it - never which, nor what any of them wrote. The
 * company's own requests stay in Requests.
 */
class PortalBoardController extends Controller
{
    use ResolvesPortalOrganization;

    public function __construct(
        private readonly TenantResolver $tenant,
        private readonly FeatureBoard $board,
        private readonly RequestCategories $categories,
    ) {}

    public function index(Request $request): View
    {
        $category = $this->categories->find((string) $request->query('category', ''))?->key;
        $sort = $request->query('sort') === FeatureBoard::SORT_NEWEST ? FeatureBoard::SORT_NEWEST : FeatureBoard::SORT_DEMAND;

        return view('portal.board.index', [
            'standings' => $this->board->published($this->organizationId($request), $category, $sort),
            'categories' => $this->categories->all(),
            'category' => $category,
            'sort' => $sort,
        ]);
    }

    public function show(Request $request, string $itemId): View
    {
        return view('portal.board.show', [
            'standing' => $this->board->standing($this->item($itemId), $this->organizationId($request), (string) $request->user()->id),
        ]);
    }

    public function back(Request $request, string $itemId): RedirectResponse
    {
        $item = $this->item($itemId);
        $backing = $request->boolean('back');

        $this->board->back($item, $this->organizationId($request), $backing);

        // Whoever backs it follows it, until they say otherwise.
        if ($backing) {
            $this->board->follow($item, $this->organizationId($request), (string) $request->user()->id, true);
        }

        return redirect()->route('portal.board.show', $itemId)
            ->with('success', $backing ? __('Your company backs this. We email you as it moves on.') : __('Your company no longer backs this.'));
    }

    public function follow(Request $request, string $itemId): RedirectResponse
    {
        $following = $request->boolean('follow');

        $this->board->follow($this->item($itemId), $this->organizationId($request), (string) $request->user()->id, $following);

        return redirect()->route('portal.board.show', $itemId)
            ->with('success', $following ? __('You follow this. We email you as it moves on.') : __('You no longer follow this.'));
    }

    private function item(string $itemId): FeatureBoardItem
    {
        return $this->board->findPublished($itemId) ?? abort(404);
    }

    private function organizationId(Request $request): string
    {
        return (string) $this->portalOrganization($this->tenant, $request)->id;
    }
}
