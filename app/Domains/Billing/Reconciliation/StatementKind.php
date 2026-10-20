<?php

declare(strict_types=1);

namespace App\Domains\Billing\Reconciliation;

/**
 * What an imported file is: a bank statement as CSV (read by its bank's
 * profile), as ISO 20022 camt.053 XML or as SWIFT MT940, or the card
 * gateway's settlement report.
 */
enum StatementKind: string
{
    case Csv = 'csv';
    case Camt053 = 'camt053';
    case Mt940 = 'mt940';
    case Settlement = 'settlement';

    public function label(): string
    {
        return match ($this) {
            self::Csv => __('Bank statement (CSV)'),
            self::Camt053 => __('Bank statement (camt.053)'),
            self::Mt940 => __('Bank statement (MT940)'),
            self::Settlement => __('Card settlement report'),
        };
    }

    /** What the file input accepts for it. */
    public function accepts(): string
    {
        return match ($this) {
            self::Csv, self::Settlement => '.csv,text/csv,.txt',
            self::Camt053 => '.xml',
            self::Mt940 => '.txt,.sta,.mt940,.940',
        };
    }

    public function isBank(): bool
    {
        return $this !== self::Settlement;
    }

    /** @return array<string, string> */
    public static function labels(): array
    {
        $labels = [];

        foreach (self::cases() as $kind) {
            $labels[$kind->value] = $kind->label();
        }

        return $labels;
    }
}
