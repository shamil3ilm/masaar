<?php

declare(strict_types=1);

namespace App\Http\Listing\Filters;

use App\Http\Listing\Filter;
use Illuminate\Contracts\Database\Query\Builder;

/**
 * Text found anywhere in a few columns: an invoice number, a buyer's name
 * or VAT number.
 *
 * The columns are ORed inside one group, so the search narrows the other
 * filters rather than widening them back out. % and _ are matched as
 * themselves: a VAT number with an underscore in a pasted link is not a
 * wildcard.
 */
final class Search implements Filter
{
    /** Longer than any number, name or VAT number anyone searches by. */
    private const MAX = 100;

    /**
     * @param  list<string>  $columns
     */
    public function __construct(
        private readonly string $key,
        private readonly string $label,
        private readonly array $columns,
        private readonly ?string $placeholder = null,
    ) {}

    public function name(): string
    {
        return $this->key;
    }

    public function keys(): array
    {
        return [$this->key];
    }

    public function label(): string
    {
        return $this->label;
    }

    public function placeholder(): ?string
    {
        return $this->placeholder;
    }

    public function read(array $query): mixed
    {
        $value = $query[$this->key] ?? null;

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return mb_substr(trim($value), 0, self::MAX);
    }

    /**
     * With an escape character named, because SQLite has none by default
     * and MySQL's is the backslash: '!' means the same on both, as
     * Catalogue's search has it. The columns are the list's own, never
     * input.
     */
    public function apply(Builder $query, mixed $value): void
    {
        $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], (string) $value).'%';

        $query->where(function (Builder $inner) use ($term): void {
            foreach ($this->columns as $column) {
                $inner->orWhereRaw($column." LIKE ? ESCAPE '!'", [$term]);
            }
        });
    }

    public function describe(mixed $value): string
    {
        return (string) $value;
    }

    public function encode(mixed $value): array
    {
        return [$this->key => (string) $value];
    }

    public function view(): string
    {
        return 'components.ui.list.search';
    }
}
