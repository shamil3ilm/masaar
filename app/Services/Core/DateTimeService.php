<?php

declare(strict_types=1);

namespace App\Services\Core;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use DateTimeZone;

/**
 * Service for handling time-based operations with proper timezone support.
 * All dates are stored in UTC, displayed in user's timezone.
 */
class DateTimeService
{
    /**
     * Get user's timezone (from user or organization).
     */
    public function getUserTimezone(): string
    {
        $user = auth()->user();

        if ($user?->timezone) {
            return $user->timezone;
        }

        if ($user?->organization?->timezone) {
            return $user->organization->timezone;
        }

        return config('app.timezone', 'UTC');
    }

    /**
     * Convert a date to user's timezone for display.
     */
    public function toUserTimezone($date, ?string $format = null): string
    {
        $carbon = $this->parse($date)->setTimezone($this->getUserTimezone());

        if ($format) {
            return $carbon->format($format);
        }

        return $carbon->toDateTimeString();
    }

    /**
     * Convert a user input date to UTC for storage.
     */
    public function toUtc($date, ?string $fromTimezone = null): Carbon
    {
        $timezone = $fromTimezone ?? $this->getUserTimezone();

        return $this->parse($date)
            ->setTimezone($timezone)
            ->setTimezone('UTC');
    }

    /**
     * Get current time in user's timezone.
     */
    public function nowInUserTimezone(): Carbon
    {
        return now()->setTimezone($this->getUserTimezone());
    }

    /**
     * Get today's date in user's timezone.
     */
    public function todayInUserTimezone(): Carbon
    {
        return $this->nowInUserTimezone()->startOfDay();
    }

    /**
     * Check if a date is today in user's timezone.
     */
    public function isToday($date): bool
    {
        return $this->parse($date)
            ->setTimezone($this->getUserTimezone())
            ->isToday();
    }

    /**
     * Get start of day in UTC for a date in user's timezone.
     */
    public function startOfDayUtc($date): Carbon
    {
        return $this->parse($date)
            ->setTimezone($this->getUserTimezone())
            ->startOfDay()
            ->setTimezone('UTC');
    }

    /**
     * Get end of day in UTC for a date in user's timezone.
     */
    public function endOfDayUtc($date): Carbon
    {
        return $this->parse($date)
            ->setTimezone($this->getUserTimezone())
            ->endOfDay()
            ->setTimezone('UTC');
    }

    /**
     * Get date range in UTC for a date in user's timezone.
     * Useful for queries that need to cover the entire day in user's timezone.
     */
    public function getDayRangeUtc($date): array
    {
        return [
            'start' => $this->startOfDayUtc($date),
            'end' => $this->endOfDayUtc($date),
        ];
    }

    /**
     * Check if we're at end of day cutoff (e.g., for batch processing).
     */
    public function isAfterCutoff(string $cutoffTime = '17:00'): bool
    {
        $now = $this->nowInUserTimezone();
        $cutoff = $now->copy()->setTimeFromTimeString($cutoffTime);

        return $now->gte($cutoff);
    }

    /**
     * Check if a date is backdated (before today).
     */
    public function isBackdated($date): bool
    {
        return $this->parse($date)
            ->setTimezone($this->getUserTimezone())
            ->startOfDay()
            ->lt($this->todayInUserTimezone());
    }

    /**
     * Check if a date is future-dated (after today).
     */
    public function isFutureDated($date): bool
    {
        return $this->parse($date)
            ->setTimezone($this->getUserTimezone())
            ->startOfDay()
            ->gt($this->todayInUserTimezone());
    }

    /**
     * Get the fiscal year for a date (based on organization settings).
     */
    public function getFiscalYear($date): ?int
    {
        $user = auth()->user();

        if (!$user?->organization) {
            return null;
        }

        $parsed = $this->parse($date);
        $fiscalYearStart = $user->organization->fiscal_year_start ?? 1; // Default January

        // If current month is before fiscal year start, we're in the previous fiscal year
        if ($parsed->month < $fiscalYearStart) {
            return $parsed->year - 1;
        }

        return $parsed->year;
    }

    /**
     * Handle DST (Daylight Saving Time) transitions.
     * Returns whether the given date is in DST.
     */
    public function isInDst($date, ?string $timezone = null): bool
    {
        $tz = new DateTimeZone($timezone ?? $this->getUserTimezone());
        $parsed = $this->parse($date);

        $transitions = $tz->getTransitions(
            $parsed->startOfYear()->timestamp,
            $parsed->endOfYear()->timestamp
        );

        foreach ($transitions as $transition) {
            if ($transition['ts'] <= $parsed->timestamp && $transition['isdst']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get date at midnight to avoid time component issues.
     */
    public function toDateOnly($date): Carbon
    {
        return $this->parse($date)->startOfDay();
    }

    /**
     * Safely compare two dates (ignoring time).
     */
    public function isSameDay($date1, $date2): bool
    {
        return $this->toDateOnly($date1)->eq($this->toDateOnly($date2));
    }

    /**
     * Get the number of business days between two dates.
     */
    public function businessDaysBetween($start, $end): int
    {
        $start = $this->parse($start)->startOfDay();
        $end = $this->parse($end)->startOfDay();

        $days = 0;
        $current = $start->copy();

        while ($current->lte($end)) {
            if (!$current->isWeekend()) {
                $days++;
            }
            $current->addDay();
        }

        return $days;
    }

    /**
     * Parse a date value to Carbon.
     */
    protected function parse($date): Carbon
    {
        if ($date instanceof Carbon) {
            return $date->copy();
        }

        if ($date instanceof CarbonImmutable) {
            return $date->toMutable();
        }

        if ($date instanceof \DateTimeInterface) {
            return Carbon::instance($date);
        }

        return Carbon::parse($date);
    }
}
