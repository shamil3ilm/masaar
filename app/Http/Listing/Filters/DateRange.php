<?php

declare(strict_types=1);

namespace App\Http\Listing\Filters;

use App\Http\Listing\Filter;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Query\Builder;
use Throwable;

/**
 * A period: today, this week, this month, last month, this quarter, or two
 * dates of the reader's own.
 *
 * The presets are read on the clock of the list's owner - an organization's
 * country, or UTC in the console - so "today" in Riyadh is not yesterday's
 * evening in UTC. A preset is kept in the link as its name, not its dates,
 * so a bookmarked "this month" is still this month next month.
 *
 * Half-open at the end: everything on the last day counts, whether the
 * column holds a date or a moment. A column that holds a moment ($instant)
 * is stored in UTC, so the day's bounds are turned into the UTC instants
 * they are on the owner's clock before they are compared.
 */
final class DateRange implements Filter
{
    public const PRESETS = ['today', 'this_week', 'this_month', 'last_month', 'this_quarter', 'custom'];

    /** A period longer than this is a mistake, and a slow one. */
    private const MAX_DAYS = 3660;

    public function __construct(
        private readonly string $column,
        private readonly string $label,
        private readonly string $timezone = 'UTC',
        private readonly bool $instant = false,
        private readonly string $key = 'period',
        private readonly string $fromKey = 'from',
        private readonly string $toKey = 'to',
    ) {}

    public function name(): string
    {
        return $this->key;
    }

    public function keys(): array
    {
        return [$this->key, $this->fromKey, $this->toKey];
    }

    public function label(): string
    {
        return $this->label;
    }

    public function fromKey(): string
    {
        return $this->fromKey;
    }

    public function toKey(): string
    {
        return $this->toKey;
    }

    /** @return array<string, string> preset => label */
    public function presets(): array
    {
        return [
            'today' => __('Today'),
            'this_week' => __('This week'),
            'this_month' => __('This month'),
            'last_month' => __('Last month'),
            'this_quarter' => __('This quarter'),
            'custom' => __('Custom dates'),
        ];
    }

    /**
     * @return array{preset: string, from: CarbonImmutable|null, to: CarbonImmutable|null}|null
     */
    public function read(array $query): mixed
    {
        $preset = $query[$this->key] ?? null;
        $from = $this->date($query[$this->fromKey] ?? null);
        $to = $this->date($query[$this->toKey] ?? null);

        // Dates typed in are custom dates whatever the preset says, so
        // nobody has to change two controls to ask one question.
        if ($from === null && $to === null) {
            if (! is_string($preset) || $preset === 'custom' || ! in_array($preset, self::PRESETS, true)) {
                return null;
            }

            [$start, $end] = $this->bounds($preset);

            return ['preset' => $preset, 'from' => $start, 'to' => $end];
        }

        // Given the wrong way round, they still mean the days between them.
        if ($from !== null && $to !== null && $from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        if ($from !== null && $to !== null && $from->diffInDays($to, true) > self::MAX_DAYS) {
            $to = $from->addDays(self::MAX_DAYS);
        }

        return ['preset' => 'custom', 'from' => $from, 'to' => $to];
    }

    public function apply(Builder $query, mixed $value): void
    {
        if ($value['from'] !== null) {
            $query->where($this->column, '>=', $this->bound($value['from']));
        }

        if ($value['to'] !== null) {
            $query->where($this->column, '<', $this->bound($value['to']->addDay()));
        }
    }

    public function describe(mixed $value): string
    {
        if ($value['preset'] !== 'custom') {
            return $this->presets()[$value['preset']];
        }

        return match (true) {
            $value['from'] !== null && $value['to'] !== null => __(':from to :to', ['from' => $value['from']->toDateString(), 'to' => $value['to']->toDateString()]),
            $value['from'] !== null => __('From :date', ['date' => $value['from']->toDateString()]),
            default => __('Until :date', ['date' => $value['to']->toDateString()]),
        };
    }

    public function encode(mixed $value): array
    {
        if ($value['preset'] !== 'custom') {
            return [$this->key => $value['preset']];
        }

        return array_filter([
            $this->fromKey => $value['from']?->toDateString(),
            $this->toKey => $value['to']?->toDateString(),
        ], fn (?string $date): bool => $date !== null);
    }

    public function view(): string
    {
        return 'components.ui.list.date-range';
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function bounds(string $preset): array
    {
        $today = CarbonImmutable::now($this->timezone)->startOfDay();
        $lastMonth = $today->subMonthNoOverflow();

        return match ($preset) {
            'today' => [$today, $today],
            'this_week' => [$today->startOfWeek(), $today->endOfWeek()->startOfDay()],
            'this_month' => [$today->startOfMonth(), $today->endOfMonth()->startOfDay()],
            'last_month' => [$lastMonth->startOfMonth(), $lastMonth->endOfMonth()->startOfDay()],
            'this_quarter' => [$today->startOfQuarter(), $today->endOfQuarter()->startOfDay()],
        };
    }

    /** A day's start as the column compares it. */
    private function bound(CarbonImmutable $day): string
    {
        return $this->instant
            ? CarbonImmutable::parse($day->toDateString(), $this->timezone)->utc()->format('Y-m-d H:i:s')
            : $day->toDateString();
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, $this->timezone);
        } catch (Throwable) {
            return null;
        }

        // 2026-02-31 parses as 3 March; a date that does not round-trip was
        // not a date.
        return $date !== false && $date !== null && $date->toDateString() === $value ? $date : null;
    }
}
