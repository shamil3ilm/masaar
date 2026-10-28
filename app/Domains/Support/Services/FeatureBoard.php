<?php

declare(strict_types=1);

namespace App\Domains\Support\Services;

use App\Domains\Auth\Models\User;
use App\Domains\Platform\Customization\Customizations;
use App\Domains\Platform\Customization\Scope;
use App\Domains\Support\DTOs\BoardStanding;
use App\Domains\Support\Enums\RequestStage;
use App\Domains\Support\Enums\TicketKind;
use App\Domains\Support\Mail\BoardUpdateMail;
use App\Domains\Support\Models\FeatureBoardBacker;
use App\Domains\Support\Models\FeatureBoardFollower;
use App\Domains\Support\Models\FeatureBoardItem;
use App\Domains\Support\Models\FeatureBoardUpdate;
use App\Domains\Support\Models\FeatureRequest;
use App\Domains\Support\Models\SupportTicket;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * The feature board: what customers asked for, as an admin publishes it.
 *
 * A feature request is triaged into a board item - a new one, worded by an
 * admin with no company's name or details, or one already there when it
 * asks for the same thing (a duplicate keeps its company's request and
 * conversation, and counts as one more company asking). An item is a
 * draft until it is published; merged into another, it is gone from the
 * board and everything on it moves across.
 *
 * Every customer sees the published items, and of each only what the admin
 * wrote, how many companies want it, and whether its own company asked for
 * it or backs it. Which other companies asked, what they wrote and who
 * backs it never leave this class except to the admin console.
 *
 * Demand is the number of companies: those whose request is linked and
 * those that back it, each counted once.
 */
class FeatureBoard
{
    public const SORT_DEMAND = 'demand';

    public const SORT_NEWEST = 'newest';

    public function __construct(
        private readonly SupportDesk $desk,
        private readonly RequestCategories $categories,
        private readonly Customizations $customizations,
    ) {}

    /**
     * The published board, for one company: each item with its demand and
     * this company's own part in it.
     *
     * @return Collection<int, BoardStanding>
     */
    public function published(string $organizationId, ?string $category = null, string $sort = self::SORT_DEMAND): Collection
    {
        $items = FeatureBoardItem::query()
            ->whereNotNull('published_at')
            ->whereNull('merged_into_id')
            ->when($category !== null, fn ($query) => $query->where('category', $category))
            ->orderByDesc('published_at')
            ->get();

        return $this->sorted($this->standings($items, $organizationId), $sort);
    }

    public function findPublished(string $itemId): ?FeatureBoardItem
    {
        $item = FeatureBoardItem::query()->with('updates')->find($itemId);

        return $item?->published() ? $item : null;
    }

    /** One item, as one company and one person in it see it. */
    public function standing(FeatureBoardItem $item, string $organizationId, ?string $userId = null): BoardStanding
    {
        $standing = $this->standings(collect([$item]), $organizationId)->first();

        return $userId === null ? $standing : $standing->following(FeatureBoardFollower::query()
            ->where('board_item_id', $item->id)->where('user_id', $userId)->exists());
    }

    /** A company says it needs this too, or takes that back. */
    public function back(FeatureBoardItem $item, string $organizationId, bool $backing): void
    {
        $this->toggle(FeatureBoardBacker::class, ['board_item_id' => $item->id, 'org_id' => $organizationId], $backing);
    }

    /** A person asks to be told when it moves on, or stops. */
    public function follow(FeatureBoardItem $item, string $organizationId, string $userId, bool $following): void
    {
        $this->toggle(FeatureBoardFollower::class, ['board_item_id' => $item->id, 'user_id' => $userId], $following, ['org_id' => $organizationId]);
    }

    /**
     * Feature requests no admin has triaged yet: not on the board, and not
     * finished.
     *
     * @return Collection<int, SupportTicket>
     */
    public function untriaged(): Collection
    {
        return SupportTicket::acrossTenants()
            ->with(['organization', 'featureRequest'])
            ->where('kind', TicketKind::Feature)
            ->whereNotIn('stage', [RequestStage::Released, RequestStage::Declined])
            ->whereHas('featureRequest', fn ($query) => $query->whereNull('board_item_id'))
            ->orderBy('created_at')
            ->get();
    }

    /**
     * Every item not merged away, for the admin board: drafts too.
     *
     * @return Collection<int, BoardStanding>
     */
    public function forStaff(?RequestStage $stage, string $sort = self::SORT_DEMAND): Collection
    {
        $items = FeatureBoardItem::query()
            ->whereNull('merged_into_id')
            ->when($stage !== null, fn ($query) => $query->where('stage', $stage))
            ->orderByDesc('created_at')
            ->get();

        return $this->sorted($this->standings($items, null), $sort);
    }

    /** An item for the console, with every request in it and who asked. */
    public function findForStaff(string $itemId): ?FeatureBoardItem
    {
        return FeatureBoardItem::query()
            ->with(['updates', 'mergedInto', 'requests.ticket.organization'])
            ->withCount(['backers', 'followers'])
            ->find($itemId);
    }

    /**
     * A new board item from a feature request, worded by the admin; the
     * request is the item's first.
     *
     * @param  array{title: string, title_ar?: string|null, summary: string, summary_ar?: string|null, category: string}  $wording
     *
     * @throws DomainException when the ticket is not an untriaged feature request
     */
    public function createFrom(SupportTicket $ticket, array $wording, bool $publish, User $staff): FeatureBoardItem
    {
        $request = $this->triageable($ticket);

        $item = $this->withNextNumber(fn (int $number): FeatureBoardItem => DB::transaction(function () use ($number, $wording, $publish, $ticket): FeatureBoardItem {
            $item = FeatureBoardItem::query()->create([
                'number' => $number,
                ...$this->wording($wording),
                'stage' => $ticket->stage === RequestStage::Submitted ? RequestStage::UnderReview : $ticket->stage,
            ]);

            if ($publish) {
                $item->forceFill(['published_at' => now()])->save();
            }

            return $item;
        }));

        $this->attach($request, $ticket, $item, $staff);

        return $item;
    }

    /**
     * A feature request that asks for what an item already is: it joins the
     * item, keeping its company's request and conversation.
     *
     * @throws DomainException when the ticket is not an untriaged feature request
     */
    public function link(SupportTicket $ticket, FeatureBoardItem $item, User $staff): void
    {
        if ($item->merged_into_id !== null) {
            throw new DomainException('That item was merged into '.$item->mergedInto?->reference().'. Link the request to that one.');
        }

        $this->attach($this->triageable($ticket), $ticket, $item, $staff);
    }

    /**
     * The admin's words, and the release that delivered it.
     *
     * @param  array{title: string, title_ar?: string|null, summary: string, summary_ar?: string|null, category: string, released_in?: string|null, release_url?: string|null}  $wording
     */
    public function reword(FeatureBoardItem $item, array $wording): void
    {
        $item->fill([
            ...$this->wording($wording),
            'released_in' => filled($wording['released_in'] ?? null) ? trim((string) $wording['released_in']) : null,
            'release_url' => filled($wording['release_url'] ?? null) ? trim((string) $wording['release_url']) : null,
        ])->save();
    }

    public function publish(FeatureBoardItem $item, bool $published): void
    {
        if ($published && $item->merged_into_id !== null) {
            throw new DomainException('A merged item cannot be published. Publish '.$item->mergedInto?->reference().' instead.');
        }

        $item->forceFill(['published_at' => $published ? ($item->published_at ?? now()) : null])->save();
    }

    /**
     * The item moves on, or gets a public note: on the board, on every
     * request in it - each company reads it on its own request, its opener
     * emailed - and to everyone following it.
     */
    public function moveStage(FeatureBoardItem $item, RequestStage $stage, ?string $note, ?string $noteAr, User $staff): void
    {
        $moved = $item->stage !== $stage;
        $note = filled($note) ? trim((string) $note) : null;
        $noteAr = filled($noteAr) ? trim((string) $noteAr) : null;

        if (! $moved && $note === null && $noteAr === null) {
            return;
        }

        DB::transaction(function () use ($item, $stage, $moved, $note, $noteAr): void {
            $item->forceFill(['stage' => $stage])->save();

            FeatureBoardUpdate::query()->create([
                'board_item_id' => $item->id,
                'stage' => $moved ? $stage : null,
                'note' => $note,
                'note_ar' => $noteAr,
            ]);
        });

        $told = [];

        foreach ($this->tickets($item) as $ticket) {
            $this->desk->moveStage($ticket, $staff, $stage, implode("\n\n", array_filter([$note, $noteAr])));
            $told[] = (string) $ticket->opened_by;
        }

        if ($item->published()) {
            $this->tellFollowers($item, $told);
        }
    }

    /**
     * A duplicate item folded into another: its requests, backers and
     * followers move across - a company already on the other counts once -
     * and it leaves the board.
     *
     * @throws DomainException when merging an item into itself or into a merged one
     */
    public function merge(FeatureBoardItem $from, FeatureBoardItem $into, User $staff): void
    {
        if ($from->is($into) || $into->merged_into_id !== null) {
            throw new DomainException('Merge into an item that is still on the board, other than this one.');
        }

        DB::transaction(function () use ($from, $into): void {
            FeatureRequest::query()->where('board_item_id', $from->id)->update(['board_item_id' => $into->id]);

            foreach ([FeatureBoardBacker::class => 'org_id', FeatureBoardFollower::class => 'user_id'] as $model => $who) {
                foreach ($model::query()->where('board_item_id', $from->id)->get() as $row) {
                    $taken = $model::withTrashed()->where('board_item_id', $into->id)->where($who, $row->{$who})->first();

                    if ($taken === null) {
                        $row->forceFill(['board_item_id' => $into->id])->save();

                        continue;
                    }

                    if ($taken->trashed() && ! $row->trashed()) {
                        $taken->restore();
                    }

                    $row->forceDelete();
                }
            }

            $from->forceFill(['merged_into_id' => $into->id, 'published_at' => null])->save();
        });

        // The requests that came across follow the item they are now in.
        foreach ($this->tickets($into) as $ticket) {
            if ($ticket->stage !== $into->stage) {
                $this->desk->moveStage($ticket, $staff, $into->stage, null);
            }
        }
    }

    /**
     * Each item's demand and this company's part in it.
     *
     * @param  Collection<int, FeatureBoardItem>  $items
     * @return Collection<int, BoardStanding>
     */
    private function standings(Collection $items, ?string $organizationId): Collection
    {
        $ids = $items->pluck('id')->all();

        $asking = FeatureRequest::query()->whereIn('board_item_id', $ids)->with('ticket:id,org_id')->get()
            ->groupBy('board_item_id')
            ->map(fn (Collection $requests): array => $requests->map(fn (FeatureRequest $request): string => (string) $request->ticket?->org_id)->filter()->unique()->values()->all());

        $backing = FeatureBoardBacker::query()->whereIn('board_item_id', $ids)->get(['board_item_id', 'org_id'])
            ->groupBy('board_item_id')
            ->map(fn (Collection $backers): array => $backers->pluck('org_id')->map(fn ($id): string => (string) $id)->unique()->values()->all());

        return $items->map(function (FeatureBoardItem $item) use ($asking, $backing, $organizationId): BoardStanding {
            $asked = $asking->get($item->id, []);
            $backed = $backing->get($item->id, []);

            return new BoardStanding(
                item: $item,
                categoryName: $this->categories->name((string) $item->category),
                demand: count(array_unique([...$asked, ...$backed])),
                requests: count($asked),
                askedByUs: $organizationId !== null && in_array($organizationId, $asked, true),
                backedByUs: $organizationId !== null && in_array($organizationId, $backed, true),
            );
        })->values();
    }

    /**
     * @param  Collection<int, BoardStanding>  $standings
     * @return Collection<int, BoardStanding>
     */
    private function sorted(Collection $standings, string $sort): Collection
    {
        return $sort === self::SORT_NEWEST
            ? $standings->values()
            : $standings->sortByDesc(fn (BoardStanding $standing): array => [$standing->demand, $standing->item->number])->values();
    }

    /**
     * The tickets of the requests in an item.
     *
     * @return Collection<int, SupportTicket>
     */
    private function tickets(FeatureBoardItem $item): Collection
    {
        return SupportTicket::acrossTenants()
            ->with('opener')
            ->where('kind', TicketKind::Feature)
            ->whereHas('featureRequest', fn ($query) => $query->where('board_item_id', $item->id))
            ->get();
    }

    /**
     * @throws DomainException
     */
    private function triageable(SupportTicket $ticket): FeatureRequest
    {
        $request = $ticket->featureRequest;

        if ($ticket->kind !== TicketKind::Feature || $request === null) {
            throw new DomainException('Only a feature request goes on the board.');
        }

        if ($request->board_item_id !== null) {
            throw new DomainException('This request is on the board already.');
        }

        return $request;
    }

    private function attach(FeatureRequest $request, SupportTicket $ticket, FeatureBoardItem $item, User $staff): void
    {
        $request->forceFill(['board_item_id' => $item->id])->save();

        if ($ticket->stage !== $item->stage) {
            $this->desk->moveStage($ticket, $staff, $item->stage, null);
        }
    }

    /**
     * @param  array<string, mixed>  $wording
     * @return array<string, string|null>
     */
    private function wording(array $wording): array
    {
        $text = fn (string $field): ?string => filled($wording[$field] ?? null) ? trim((string) $wording[$field]) : null;

        return [
            'title' => (string) $text('title'),
            'title_ar' => $text('title_ar'),
            'summary' => (string) $text('summary'),
            'summary_ar' => $text('summary_ar'),
            'category' => (string) $wording['category'],
        ];
    }

    /**
     * Back or follow, or take it back: a removed row is brought back rather
     * than a second made.
     *
     * @param  class-string<FeatureBoardBacker|FeatureBoardFollower>  $model
     * @param  array<string, string>  $key
     * @param  array<string, string>  $extra
     */
    private function toggle(string $model, array $key, bool $on, array $extra = []): void
    {
        $row = $model::withTrashed()->where($key)->first();

        if ($on) {
            match (true) {
                $row === null => $model::query()->create([...$key, ...$extra]),
                $row->trashed() => $row->restore(),
                default => null,
            };

            return;
        }

        if ($row !== null && ! $row->trashed()) {
            $row->delete();
        }
    }

    /**
     * Everyone following it, but those already told on their own request,
     * unless they chose not to be emailed.
     *
     * @param  list<string>  $told
     */
    private function tellFollowers(FeatureBoardItem $item, array $told): void
    {
        $followers = FeatureBoardFollower::query()->with('user')->where('board_item_id', $item->id)->whereNotIn('user_id', $told)->get();

        foreach ($followers as $follower) {
            $user = $follower->user;

            if ($user === null || ! $user->isActive()
                || ! $this->customizations->get('notifications.request_updates', Scope::member((string) $follower->org_id, (string) $user->id))) {
                continue;
            }

            // The account, so the mail is in the language they read in.
            try {
                Mail::to($user)->send(new BoardUpdateMail($item->reference(), (string) $item->title, $item->title_ar, route('portal.board.show', $item->id)));
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    /**
     * The next item number; two made at once retry on the unique index.
     *
     * @param  callable(int): FeatureBoardItem  $create
     */
    private function withNextNumber(callable $create): FeatureBoardItem
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $create((int) FeatureBoardItem::query()->max('number') + 1);
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt >= 5) {
                    throw $e;
                }
            }
        }
    }
}
