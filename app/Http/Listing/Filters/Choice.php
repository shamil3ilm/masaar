<?php

declare(strict_types=1);

namespace App\Http\Listing\Filters;

use App\Http\Listing\Filter;
use Closure;
use Illuminate\Contracts\Database\Query\Builder;

/**
 * One of a fixed set of values: a status, a branch, a currency, a person.
 *
 * Only an offered value narrows anything. A value nobody offered - a
 * branch of another organization, a status that does not exist - is
 * ignored, which is what makes it safe to take an id from a URL here: the
 * options are the caller's own, already confined to the tenant.
 */
final class Choice implements Filter
{
    /**
     * @param  array<string, string>  $options  value => label
     * @param  string|Closure(Builder, string): void  $column  the column it
     *                                                         matches, or how
     *                                                         it narrows
     * @param  string|null  $anyLabel  what the list shows when none is chosen,
     *                                 where that is not everything
     */
    public function __construct(
        private readonly string $key,
        private readonly string $label,
        private readonly array $options,
        private readonly string|Closure $column,
        private readonly ?string $anyLabel = null,
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

    public function anyLabel(): string
    {
        return $this->anyLabel ?? __('Any');
    }

    /** @return array<string, string> */
    public function options(): array
    {
        return $this->options;
    }

    public function read(array $query): mixed
    {
        $value = $query[$this->key] ?? null;

        return is_string($value) && array_key_exists($value, $this->options) ? $value : null;
    }

    public function apply(Builder $query, mixed $value): void
    {
        if ($this->column instanceof Closure) {
            ($this->column)($query, (string) $value);

            return;
        }

        $query->where($this->column, $value);
    }

    public function describe(mixed $value): string
    {
        return $this->options[(string) $value] ?? (string) $value;
    }

    public function encode(mixed $value): array
    {
        return [$this->key => (string) $value];
    }

    public function view(): string
    {
        return 'components.ui.list.choice';
    }
}
