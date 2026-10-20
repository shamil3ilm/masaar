<?php

declare(strict_types=1);

namespace App\Domains\Support\Http\Controllers;

use App\Domains\Platform\Http\Middleware\AuditAdminAction;
use App\Domains\Support\DTOs\BoardStanding;
use App\Domains\Support\DTOs\RequestCategory;
use App\Domains\Support\Enums\RequestStage;
use App\Domains\Support\Models\FeatureBoardItem;
use App\Domains\Support\Models\SupportTicket;
use App\Domains\Support\Services\FeatureBoard;
use App\Domains\Support\Services\RequestCategories;
use App\Domains\Support\Services\Requests;
use App\Domains\Support\Services\SupportDesk;
use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Admin › Feature board: triage what customers asked for.
 *
 * A feature request becomes a board item, worded here with no company's
 * name or details, or joins one already there (a duplicate, which keeps
 * its company's request). Items are sorted by demand, published or kept as
 * drafts, moved through their stages with public notes, linked to the
 * release that delivered them and merged when two turn out to be one. A
 * request that is really work for one customer becomes a customization.
 */
class AdminBoardController extends Controller
{
    public function __construct(
        private readonly FeatureBoard $board,
        private readonly SupportDesk $desk,
        private readonly Requests $requests,
        private readonly RequestCategories $categories,
    ) {}

    public function index(Request $request): View
    {
        $stage = RequestStage::tryFrom((string) $request->query('stage', ''));
        $sort = $request->query('sort') === FeatureBoard::SORT_NEWEST ? FeatureBoard::SORT_NEWEST : FeatureBoard::SORT_DEMAND;

        return view('admin.board.index', [
            'untriaged' => $this->board->untriaged(),
            'standings' => $this->board->forStaff($stage, $sort),
            'stages' => RequestStage::cases(),
            'stage' => $stage,
            'sort' => $sort,
        ]);
    }

    public function show(string $itemId): View
    {
        $item = $this->item($itemId);
        $standings = $this->board->forStaff(null, FeatureBoard::SORT_NEWEST);

        return view('admin.board.show', [
            'item' => $item,
            'standing' => $standings->first(fn (BoardStanding $standing): bool => $standing->item->is($item)),
            'others' => $standings->reject(fn (BoardStanding $standing): bool => $standing->item->is($item))->values(),
            'stages' => RequestStage::cases(),
            'categories' => $this->categories->all(),
        ]);
    }

    /** A new item from a feature request, in the admin's words. */
    public function store(Request $request, string $ticketId): RedirectResponse
    {
        $ticket = $this->ticket($ticketId);
        $request->attributes->set(AuditAdminAction::TARGET, ['SupportTicket', $ticketId]);

        $data = $request->validate([...$this->wordingRules(), 'publish' => ['sometimes', 'boolean']]);

        try {
            $item = $this->board->createFrom($ticket, $data, (bool) ($data['publish'] ?? false), $request->user());
        } catch (DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.board.show', $item->id)->with('success', 'Board item '.$item->reference().' made from '.$ticket->reference().'.');
    }

    /** A request that asks for what an item already is joins it. */
    public function link(Request $request, string $ticketId): RedirectResponse
    {
        $ticket = $this->ticket($ticketId);
        $request->attributes->set(AuditAdminAction::TARGET, ['SupportTicket', $ticketId]);

        $item = $this->item((string) $request->validate(['item_id' => ['required', 'uuid']])['item_id']);

        try {
            $this->board->link($ticket, $item, $request->user());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.board.show', $item->id)->with('success', $ticket->reference().' joined '.$item->reference().'. Its company counts as one more asking.');
    }

    /** Work for one customer after all: a customization, priced on its ticket. */
    public function convert(Request $request, string $ticketId): RedirectResponse
    {
        $ticket = $this->ticket($ticketId);
        $request->attributes->set(AuditAdminAction::TARGET, ['SupportTicket', $ticketId]);

        $note = $request->validate(['note' => ['nullable', 'string', 'max:5000']])['note'] ?? null;

        try {
            $this->requests->convertToCustomization($ticket, $request->user(), $note);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.support.show', $ticketId)->with('success', 'Now a customization. Agree its work and price here.');
    }

    public function update(Request $request, string $itemId): RedirectResponse
    {
        $item = $this->item($itemId);

        $data = $request->validate([
            ...$this->wordingRules(),
            'released_in' => ['nullable', 'string', 'max:80'],
            'release_url' => ['nullable', 'url:https', 'max:255'],
        ]);

        $this->board->reword($item, $data);

        return redirect()->route('admin.board.show', $itemId)->with('success', 'Board item saved.');
    }

    public function publish(Request $request, string $itemId): RedirectResponse
    {
        $item = $this->item($itemId);
        $published = $request->boolean('published');

        try {
            $this->board->publish($item, $published);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.board.show', $itemId)
            ->with('success', $published ? 'Published. Every customer sees it on the board.' : 'Unpublished. Only admins see it now.');
    }

    public function stage(Request $request, string $itemId): RedirectResponse
    {
        $item = $this->item($itemId);

        $data = $request->validate([
            'stage' => ['required', Rule::enum(RequestStage::class)],
            'note' => ['nullable', 'string', 'max:5000'],
            'note_ar' => ['nullable', 'string', 'max:5000'],
        ]);

        $this->board->moveStage($item, RequestStage::from($data['stage']), $data['note'] ?? null, $data['note_ar'] ?? null, $request->user());

        return redirect()->route('admin.board.show', $itemId)->with('success', 'Update published. Every company that asked, and everyone following, is told.');
    }

    public function merge(Request $request, string $itemId): RedirectResponse
    {
        $from = $this->item($itemId);
        $into = $this->item((string) $request->validate(['into_id' => ['required', 'uuid']])['into_id']);

        try {
            $this->board->merge($from, $into, $request->user());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.board.show', $into->id)->with('success', $from->reference().' merged into '.$into->reference().'. Every company that asked is kept.');
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function wordingRules(): array
    {
        return [
            'title' => ['required', 'string', 'max:160'],
            'title_ar' => ['nullable', 'string', 'max:160'],
            'summary' => ['required', 'string', 'max:5000'],
            'summary_ar' => ['nullable', 'string', 'max:5000'],
            'category' => ['required', Rule::in(array_map(fn (RequestCategory $category): string => $category->key, $this->categories->all()))],
        ];
    }

    private function item(string $itemId): FeatureBoardItem
    {
        return $this->board->findForStaff($itemId) ?? abort(404);
    }

    private function ticket(string $ticketId): SupportTicket
    {
        return $this->desk->findForStaff($ticketId) ?? abort(404);
    }
}
