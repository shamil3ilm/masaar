<?php

declare(strict_types=1);

namespace App\Http\Listing\Filters;

use App\Http\Listing\Filter;
use Illuminate\Contracts\Database\Query\Builder;

/**
 * Between two amounts, either of them left open.
 *
 * Each bound is a plain decimal - digits, an optional point and at most two
 * places - so a value with an exponent, a comma or a currency sign narrows
 * nothing rather than being read as something it did not say.
 */
final class NumberRange implements Filter
{
    public function __construct(
        private readonly string $column,
        private readonly string $label,
        private readonly string $minKey = 'amount_min',
        private readonly string $maxKey = 'amount_max',
    ) {}

    public function name(): string
    {
        return $this->minKey;
    }

    public function keys(): array
    {
        return [$this->minKey, $this->maxKey];
    }

    public function label(): string
    {
        return $this->label;
    }

    public function minKey(): string
    {
        return $this->minKey;
    }

    public function maxKey(): string
    {
        return $this->maxKey;
    }

    /**
     * @return array{min: string|null, max: string|null}|null
     */
    public function read(array $query): mixed
    {
        $min = $this->amount($query[$this->minKey] ?? null);
        $max = $this->amount($query[$this->maxKey] ?? null);

        if ($min === null && $max === null) {
            return null;
        }

        if ($min !== null && $max !== null && (float) $min > (float) $max) {
            [$min, $max] = [$max, $min];
        }

        return ['min' => $min, 'max' => $max];
    }

    public function apply(Builder $query, mixed $value): void
    {
        if ($value['min'] !== null) {
            $query->where($this->column, '>=', $value['min']);
        }

        if ($value['max'] !== null) {
            $query->where($this->column, '<=', $value['max']);
        }
    }

    public function describe(mixed $value): string
    {
        return match (true) {
            $value['min'] !== null && $value['max'] !== null => __(':min to :max', ['min' => $value['min'], 'max' => $value['max']]),
            $value['min'] !== null => __('At least :amount', ['amount' => $value['min']]),
            default => __('At most :amount', ['amount' => $value['max']]),
        };
    }

    public function encode(mixed $value): array
    {
        return array_filter([
            $this->minKey => $value['min'],
            $this->maxKey => $value['max'],
        ], fn (?string $amount): bool => $amount !== null);
    }

    public function view(): string
    {
        return 'components.ui.list.number-range';
    }

    private function amount(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/^-?\d{1,13}(\.\d{1,2})?$/', trim($value)) !== 1) {
            return null;
        }

        return trim($value);
    }
}
