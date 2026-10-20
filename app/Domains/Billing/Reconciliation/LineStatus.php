<?php

declare(strict_types=1);

namespace App\Domains\Billing\Reconciliation;

/**
 * Where a statement line stands: nothing found for it yet, a match
 * suggested and waiting for an admin, matched to money the platform
 * records, or set aside as not a customer's payment.
 */
enum LineStatus: string
{
    case Unmatched = 'unmatched';
    case Suggested = 'suggested';
    case Matched = 'matched';
    case Ignored = 'ignored';

    public function label(): string
    {
        return match ($this) {
            self::Unmatched => __('Unmatched'),
            self::Suggested => __('Suggested'),
            self::Matched => __('Matched'),
            self::Ignored => __('Ignored'),
        };
    }

    /** The badge's meaning (x-ui.badge tone). */
    public function tone(): string
    {
        return match ($this) {
            self::Unmatched => 'warning',
            self::Suggested => 'info',
            self::Matched => 'success',
            self::Ignored => 'neutral',
        };
    }

    /** @return array<string, string> */
    public static function labels(): array
    {
        $labels = [];

        foreach (self::cases() as $status) {
            $labels[$status->value] = $status->label();
        }

        return $labels;
    }
}
