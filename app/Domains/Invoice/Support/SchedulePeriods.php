<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Support;

use App\Domains\Invoice\Enums\ScheduleFrequency;
use App\Domains\Invoice\Models\InvoiceSchedule;
use Carbon\CarbonImmutable;

/**
 * A schedule's periods: the dates each one covers, by its index from the
 * first.
 *
 * Civil dates, as an invoice's supply dates are - the days the
 * organization's country lives on, never instants - so they are worked on
 * as dates at midnight UTC and read with toDateString(). A month is a
 * calendar month from the start date, not 30 days: a schedule starting on
 * the 31st bills from the last day of a shorter month and back to the 31st
 * after it. A period ends the day before the next one starts. A term is
 * the dates the organization gave for it.
 *
 * A schedule ends at its count of periods, or with the last period that
 * starts on or before its end date, or with its last term; with none of
 * them it runs until it is stopped.
 */
final readonly class SchedulePeriods
{
    /**
     * @param  list<array{start_date: string, end_date: string}>  $terms
     */
    public function __construct(
        private ScheduleFrequency $frequency,
        private CarbonImmutable $start,
        private ?CarbonImmutable $end = null,
        private ?int $occurrences = null,
        private array $terms = [],
    ) {}

    public static function of(InvoiceSchedule $schedule): self
    {
        return new self(
            $schedule->frequency,
            self::date($schedule->start_date->toDateString()),
            $schedule->end_date === null ? null : self::date($schedule->end_date->toDateString()),
            $schedule->occurrences,
            array_values((array) ($schedule->terms ?? [])),
        );
    }

    public static function date(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date, 'UTC')->startOfDay();
    }

    /**
     * The period at an index, or null when the schedule has ended by then.
     *
     * @return array{start: CarbonImmutable, end: CarbonImmutable}|null
     */
    public function at(int $index): ?array
    {
        if ($index < 0 || ($this->occurrences !== null && $index >= $this->occurrences)) {
            return null;
        }

        if ($this->frequency->hasTerms()) {
            $term = $this->terms[$index] ?? null;

            return $term === null ? null : ['start' => self::date($term['start_date']), 'end' => self::date($term['end_date'])];
        }

        $start = $this->startOf($index);

        if ($this->end !== null && $start->gt($this->end)) {
            return null;
        }

        return ['start' => $start, 'end' => $this->startOf($index + 1)->subDay()];
    }

    /**
     * How many periods there are; null for a schedule that runs until it is
     * stopped.
     */
    public function count(): ?int
    {
        if ($this->frequency->hasTerms()) {
            return min(count($this->terms), $this->occurrences ?? PHP_INT_MAX);
        }

        if ($this->occurrences !== null) {
            return $this->end === null ? $this->occurrences : min($this->occurrences, $this->countTo($this->end));
        }

        return $this->end === null ? null : $this->countTo($this->end);
    }

    /**
     * The periods from an index on, at most $limit of them.
     *
     * @return list<array{period: int, start: CarbonImmutable, end: CarbonImmutable}>
     */
    public function from(int $index, int $limit): array
    {
        $periods = [];

        for ($i = $index; count($periods) < $limit; $i++) {
            $period = $this->at($i);

            if ($period === null) {
                break;
            }

            $periods[] = ['period' => $i, ...$period];
        }

        return $periods;
    }

    private function startOf(int $index): CarbonImmutable
    {
        $months = $this->frequency->months();

        return $months === null
            ? $this->start->addDays(7 * $index)
            : $this->start->addMonthsNoOverflow($months * $index);
    }

    /** The periods starting on or before a date. */
    private function countTo(CarbonImmutable $end): int
    {
        $count = 0;

        while (! $this->startOf($count)->gt($end)) {
            $count++;
        }

        return $count;
    }
}
