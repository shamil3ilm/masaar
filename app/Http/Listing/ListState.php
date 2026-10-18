<?php

declare(strict_types=1);

namespace App\Http\Listing;

use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * One reading of a list: the filters the query string asked for, after
 * each was checked, the order and the page size - and every link the list
 * draws from them.
 *
 * Immutable. The links are built from what was understood, not from what
 * was typed, so a chip, a sort header, the next page and the CSV all ask
 * for exactly what the list is showing, and a malformed parameter does not
 * survive the first click.
 */
final class ListState
{
    /**
     * @param  array<string, mixed>  $values  each active filter's value, by name
     * @param  list<array{name: string, url: string, delete: string}>  $views
     */
    private function __construct(
        public readonly ListSpec $spec,
        private readonly array $values,
        public readonly string $sort,
        public readonly string $direction,
        public readonly int $perPage,
        public readonly string $url,
        public readonly array $views = [],
        public readonly ?string $exportUrl = null,
        public readonly ?string $saveUrl = null,
    ) {}

    public static function read(ListSpec $spec, Request $request): self
    {
        return self::fromQuery($spec, $request->query(), $request->url());
    }

    /**
     * @param  array<string, mixed>  $query
     */
    public static function fromQuery(ListSpec $spec, array $query, string $url): self
    {
        $values = [];

        foreach ($spec->filters as $filter) {
            $value = $filter->read($query);

            if ($value !== null) {
                $values[$filter->name()] = $value;
            }
        }

        $sort = is_string($query['sort'] ?? null) && isset($spec->sorts[$query['sort']]) ? $query['sort'] : $spec->defaultSort;
        $direction = in_array($query['dir'] ?? null, ['asc', 'desc'], true) ? $query['dir'] : $spec->sorts[$sort]->direction;
        $perPage = is_string($query['per_page'] ?? null) && in_array((int) $query['per_page'], self::sizes($spec), true)
            ? (int) $query['per_page']
            : $spec->defaultPerPage;

        return new self($spec, $values, $sort, $direction, $perPage, $url);
    }

    /**
     * The same reading with the person's saved views, and where the CSV and
     * saving a view go.
     *
     * @param  list<SavedView>  $views
     */
    public function with(array $views, ?string $exportUrl, ?string $saveUrl, callable $deleteUrl): self
    {
        $links = array_map(fn (SavedView $view): array => [
            'name' => $view->name,
            'url' => $this->url.($view->query === '' ? '' : '?'.$view->query),
            'delete' => $deleteUrl($view),
        ], $views);

        $export = $exportUrl === null ? null : $exportUrl.($this->queryString() === '' ? '' : '?'.$this->queryString());

        return new self($this->spec, $this->values, $this->sort, $this->direction, $this->perPage, $this->url, $links, $export, $saveUrl);
    }

    /**
     * Narrow and order the query as asked. Ties in the order are settled by
     * the spec's tiebreaker, in the same direction.
     *
     * @template TBuilder of Builder
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public function apply(Builder $query): Builder
    {
        $this->narrow($query);

        $query->orderBy($this->spec->sorts[$this->sort]->column, $this->direction)
            ->orderBy($this->spec->tiebreaker, $this->direction);

        return $query;
    }

    /**
     * The filters alone, for a count or a total over the same rows.
     *
     * @template TBuilder of Builder
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public function narrow(Builder $query): Builder
    {
        foreach ($this->spec->filters as $filter) {
            if (array_key_exists($filter->name(), $this->values)) {
                $filter->apply($query, $this->values[$filter->name()]);
            }
        }

        return $query;
    }

    /** One page of the query, its links asking for this reading. */
    public function paginate(Builder $query): LengthAwarePaginator
    {
        return $this->apply($query)->paginate($this->perPage)->appends($this->query());
    }

    /**
     * The page sizes offered: the usual ones, and the list's own default
     * where that is none of them.
     *
     * @return list<int>
     */
    public function pageSizes(): array
    {
        return self::sizes($this->spec);
    }

    /** The value a filter was given, or null when it narrows nothing. */
    public function value(string $name): mixed
    {
        return $this->values[$name] ?? null;
    }

    /** What a control shows: the understood value of one query key. */
    public function input(string $key): string
    {
        return $this->query()[$key] ?? '';
    }

    public function filtered(): bool
    {
        return $this->values !== [];
    }

    /** How many filters narrow the list. */
    public function count(): int
    {
        return count($this->values);
    }

    /**
     * @return list<Chip>
     */
    public function chips(): array
    {
        $chips = [];

        foreach ($this->spec->filters as $filter) {
            if (! array_key_exists($filter->name(), $this->values)) {
                continue;
            }

            $without = array_diff_key($this->query(), array_flip($filter->keys()));

            $chips[] = new Chip($filter->label(), $filter->describe($this->values[$filter->name()]), $this->link($without));
        }

        return $chips;
    }

    /**
     * The query string that asks for this reading again: the filters, and
     * the order and page size where they are not the list's own.
     *
     * @return array<string, string>
     */
    public function query(): array
    {
        $query = [];

        foreach ($this->spec->filters as $filter) {
            if (array_key_exists($filter->name(), $this->values)) {
                $query += $filter->encode($this->values[$filter->name()]);
            }
        }

        return $query + $this->order();
    }

    public function queryString(): string
    {
        return http_build_query($this->query(), '', '&', PHP_QUERY_RFC3986);
    }

    /** Every filter off; the order and page size kept. */
    public function clearUrl(): string
    {
        return $this->link($this->order());
    }

    /**
     * The list ordered by one sort: the other way round when it already is,
     * otherwise that sort's own direction first. Back to the first page.
     */
    public function sortUrl(string $key): string
    {
        $direction = $this->sort === $key
            ? ($this->direction === 'asc' ? 'desc' : 'asc')
            : ($this->spec->sorts[$key]->direction ?? 'desc');

        return $this->link([...$this->query(), 'sort' => $key, 'dir' => $direction]);
    }

    /** asc or desc when the list is ordered by this sort, otherwise null. */
    public function sortedBy(string $key): ?string
    {
        return $this->sort === $key ? $this->direction : null;
    }

    /**
     * @return list<int>
     */
    private static function sizes(ListSpec $spec): array
    {
        $sizes = array_values(array_unique([...ListSpec::PAGE_SIZES, $spec->defaultPerPage]));
        sort($sizes);

        return $sizes;
    }

    /**
     * @return array<string, string>
     */
    private function order(): array
    {
        return array_filter([
            'sort' => $this->sort !== $this->spec->defaultSort ? $this->sort : null,
            'dir' => $this->direction !== $this->spec->sorts[$this->sort]->direction ? $this->direction : null,
            'per_page' => $this->perPage !== $this->spec->defaultPerPage ? (string) $this->perPage : null,
        ], fn (?string $value): bool => $value !== null);
    }

    /**
     * @param  array<string, string>  $query
     */
    private function link(array $query): string
    {
        return $this->url.($query === [] ? '' : '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986));
    }
}
