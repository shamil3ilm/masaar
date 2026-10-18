<?php

declare(strict_types=1);

namespace App\Http\Listing\Filters;

use App\Http\Listing\Filter;
use Illuminate\Contracts\Database\Query\Builder;

/**
 * One exact value of a given shape: an organization's id pasted from
 * another screen. Anything not of the shape narrows nothing.
 */
final class Equals implements Filter
{
    public function __construct(
        private readonly string $key,
        private readonly string $label,
        private readonly string $column,
        private readonly string $pattern = '/^[0-9a-fA-F-]{36}$/',
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

        return is_string($value) && preg_match($this->pattern, trim($value)) === 1 ? trim($value) : null;
    }

    public function apply(Builder $query, mixed $value): void
    {
        $query->where($this->column, $value);
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
