<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Engine\Services;

use App\Domains\Compliance\Engine\DTOs\VatReturnBox;
use App\Domains\Compliance\Engine\DTOs\VatReturnEntry;

/**
 * Adds documents into the boxes of a return as a country drafts it: one
 * entry per document and box, each rounded to the cent, so a box is exactly
 * the sum of the entries it lists.
 */
final class VatReturnTally
{
    /** @var array<string, array<string, array{kind: string, id: string, number: string, date: string, party: string, amount: float, adjustment: float, vat: float}>> */
    private array $entries = [];

    public function add(string $box, string $kind, string $id, string $number, string $date, string $party, float $amount, float $adjustment, float $vat): void
    {
        $key = $kind.':'.$id;
        $this->entries[$box][$key] ??= ['kind' => $kind, 'id' => $id, 'number' => $number, 'date' => $date, 'party' => $party, 'amount' => 0.0, 'adjustment' => 0.0, 'vat' => 0.0];
        $this->entries[$box][$key]['amount'] += $amount;
        $this->entries[$box][$key]['adjustment'] += $adjustment;
        $this->entries[$box][$key]['vat'] += $vat;
    }

    /** @return list<VatReturnEntry> */
    public function entries(string $box): array
    {
        return array_values(array_map(fn (array $entry): VatReturnEntry => new VatReturnEntry(
            $entry['kind'], $entry['id'], $entry['number'], $entry['date'], $entry['party'],
            self::cents($entry['amount']), self::cents($entry['adjustment']), self::cents($entry['vat']),
        ), $this->entries[$box] ?? []));
    }

    /** The box with its entries, each column the sum of theirs; null leaves a column the form does not have. */
    public function box(string $key, string $label, bool $amount = true, bool $adjustment = true, bool $vat = true): VatReturnBox
    {
        $entries = $this->entries($key);
        $sum = fn (string $column): float => self::cents(array_sum(array_map(fn (VatReturnEntry $entry): float => $entry->{$column}, $entries)));

        return new VatReturnBox($key, $label,
            $amount ? $sum('amount') : null,
            $adjustment ? $sum('adjustment') : null,
            $vat ? $sum('vat') : null,
            $entries);
    }

    /**
     * A box that is the sum of others, or typed in: it lists no documents.
     */
    public static function total(string $key, string $label, ?float $amount, ?float $adjustment, ?float $vat): VatReturnBox
    {
        $round = fn (?float $value): ?float => $value === null ? null : self::cents($value);

        return new VatReturnBox($key, $label, $round($amount), $round($adjustment), $round($vat), [], false);
    }

    /**
     * @param  list<VatReturnBox>  $boxes
     */
    public static function sum(array $boxes, string $column): float
    {
        return self::cents(array_sum(array_map(fn (VatReturnBox $box): float => (float) $box->{$column}, $boxes)));
    }

    public static function cents(float $amount): float
    {
        return round($amount, 2) + 0.0;
    }
}
