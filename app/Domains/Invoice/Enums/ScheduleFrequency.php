<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Enums;

/**
 * How often a schedule bills: a period of one week, one, three or twelve
 * months from its start date, or a school's terms, each with its own dates.
 */
enum ScheduleFrequency: string
{
    case Weekly = 'weekly';
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case Termly = 'termly';
    case Yearly = 'yearly';

    public function label(): string
    {
        return match ($this) {
            self::Weekly => __('Weekly'),
            self::Monthly => __('Monthly'),
            self::Quarterly => __('Quarterly'),
            self::Termly => __('Termly'),
            self::Yearly => __('Yearly'),
        };
    }

    /** Months in one period; null for a week or a term, which are not months. */
    public function months(): ?int
    {
        return match ($this) {
            self::Monthly => 1,
            self::Quarterly => 3,
            self::Yearly => 12,
            default => null,
        };
    }

    /** Whether its periods are the dates the organization gives, not a step from the start. */
    public function hasTerms(): bool
    {
        return $this === self::Termly;
    }

    /**
     * @return array<string, string> value => label
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
