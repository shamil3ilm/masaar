<?php

declare(strict_types=1);

namespace App\Domains\Platform\Website;

use App\Domains\Platform\Models\WebsiteSection;
use DomainException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The home page's sections as Admin › Website has set them, over their
 * shipped defaults (SectionCatalogue): which are shown, in what order, and
 * what each says in English and Arabic.
 *
 * A section with no row, or no content in its row, shows its default.
 * Saving text the same as the default stores none, so "edited" means it
 * differs. Reset empties the text and leaves on/off and order alone.
 *
 * Every visitor's page asks, so the rows are cached as one entry and
 * forgotten on write - again after the write commits, so a read between the
 * two cannot put the old text back for good.
 */
class WebsiteSections
{
    private const CACHE_KEY = 'website.sections';

    /** The gap between positions, so a section added later can slot in between. */
    private const STEP = 10;

    public function __construct(
        private readonly SectionCatalogue $catalogue,
        private readonly SectionInput $input,
    ) {}

    /**
     * Every section, in page order: the pinned one first, then by position.
     *
     * @return list<SectionState>
     */
    public function all(): array
    {
        $rows = $this->rows();
        $states = [];
        $index = 0;

        foreach ($this->catalogue->all() as $key => $definition) {
            $row = $rows[$key] ?? null;
            // Where the catalogue puts it, until an admin moves it.
            $default = $definition->pinned ? -1 : ++$index * self::STEP;
            $states[] = new SectionState(
                definition: $definition,
                enabled: $definition->pinned || ($row['enabled'] ?? $definition->enabledByDefault),
                position: $definition->pinned ? -1 : ($row['position'] ?? $default),
                edited: ($row['content'] ?? null) !== null,
            );
        }

        // Stable: a tie keeps the catalogue's order.
        usort($states, fn (SectionState $a, SectionState $b): int => $a->position <=> $b->position);

        return $states;
    }

    /**
     * What the home page shows, in order, in the page's language.
     *
     * @return array<string, SectionContent> by key
     */
    public function page(?string $locale = null): array
    {
        $page = [];

        foreach ($this->all() as $state) {
            if ($state->enabled) {
                $page[$state->definition->key] = $this->content($state->definition->key, $locale);
            }
        }

        return $page;
    }

    public function shows(string $key): bool
    {
        foreach ($this->all() as $state) {
            if ($state->definition->key === $key) {
                return $state->enabled;
            }
        }

        return false;
    }

    public function content(string $key, ?string $locale = null): SectionContent
    {
        $locale ??= app()->getLocale();

        return new SectionContent($key, $this->texts($key), in_array($locale, SectionCatalogue::LOCALES, true) ? $locale : 'en');
    }

    /**
     * A section's text in both languages: an admin's, else the default.
     *
     * @return array<string, mixed>
     */
    public function texts(string $key): array
    {
        return $this->rows()[$key]['content'] ?? $this->catalogue->defaults($key);
    }

    /**
     * Save a section's text from its edit form, checked.
     *
     * @param  array<string, mixed>  $input
     * @return array{0: array<string, mixed>, 1: array<string, mixed>} the text before and after
     */
    public function save(string $key, array $input): array
    {
        $definition = $this->catalogue->get($key);
        $before = $this->texts($key);
        $content = $this->input->clean($definition, $input);

        $this->write($key, fn (WebsiteSection $row) => $row->content = $content === $this->catalogue->defaults($key) ? null : $content);

        return [$before, $content];
    }

    /** Back to the shipped text. Shown or not, and its place, stay. */
    public function reset(string $key): void
    {
        $this->catalogue->get($key);

        $this->write($key, fn (WebsiteSection $row) => $row->content = null);
    }

    /**
     * @throws DomainException for the pinned section
     */
    public function setEnabled(string $key, bool $enabled): void
    {
        if ($this->catalogue->get($key)->pinned) {
            throw new DomainException(__('This section is always shown.'));
        }

        $this->write($key, fn (WebsiteSection $row) => $row->enabled = $enabled);
    }

    /**
     * One place earlier or later. Every movable section's position is
     * written afresh, so the order is exactly the one on screen.
     *
     * @param  'up'|'down'  $direction
     *
     * @throws DomainException for the pinned section
     */
    public function move(string $key, string $direction): void
    {
        if ($this->catalogue->get($key)->pinned) {
            throw new DomainException(__('This section is always first.'));
        }

        $order = array_values(array_map(
            fn (SectionState $state): string => $state->definition->key,
            array_filter($this->all(), fn (SectionState $state): bool => ! $state->definition->pinned),
        ));

        $from = (int) array_search($key, $order, true);
        $to = $direction === 'up' ? $from - 1 : $from + 1;

        if ($to < 0 || $to >= count($order)) {
            return;
        }

        [$order[$from], $order[$to]] = [$order[$to], $order[$from]];

        DB::transaction(function () use ($order): void {
            foreach ($order as $index => $each) {
                $this->write($each, fn (WebsiteSection $row) => $row->position = ($index + 1) * self::STEP);
            }
        });
    }

    /**
     * @param  callable(WebsiteSection): mixed  $change
     */
    private function write(string $key, callable $change): void
    {
        DB::transaction(function () use ($key, $change): void {
            $definition = $this->catalogue->get($key);
            $state = collect($this->all())->first(fn (SectionState $state): bool => $state->definition->key === $key);
            $row = WebsiteSection::query()->lockForUpdate()->firstOrNew(['key' => $key], [
                'enabled' => $definition->enabledByDefault,
                'position' => max(0, $state?->position ?? 0),
            ]);

            $change($row);
            $row->save();
        });

        Cache::forget(self::CACHE_KEY);
        DB::afterCommit(fn () => Cache::forget(self::CACHE_KEY));
    }

    /**
     * The stored rows, by key.
     *
     * @return array<string, array{enabled: bool, position: int, content: array<string, mixed>|null}>
     */
    private function rows(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, fn (): array => WebsiteSection::query()->get()
            ->mapWithKeys(fn (WebsiteSection $row): array => [$row->key => [
                'enabled' => $row->enabled,
                'position' => $row->position,
                'content' => $row->content,
            ]])
            ->all());
    }
}
