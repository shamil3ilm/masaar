<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Support;

/**
 * Share a document-level allowance out across tax categories.
 *
 * A discount on the whole invoice reduces what is taxable, and the
 * reduction has to reach each category in proportion to what it
 * contributed — otherwise one category absorbs the lot and both its base
 * and its neighbour's are wrong. Charging VAT on the amount before the
 * discount overstates the tax the taxpayer owes.
 *
 * The last category takes the rounding, so the shares always sum back to
 * the allowance exactly and TaxExclusiveAmount lands on
 * LineExtensionAmount minus it.
 */
final class AllowanceApportionment
{
    /**
     * @param  array<string, float>  $netsByKey  Undiscounted net per category
     * @return array<string, float> The allowance apportioned to each
     */
    public static function apportion(array $netsByKey, float $allowance): array
    {
        $total = array_sum($netsByKey);

        if ($allowance <= 0.0 || $total <= 0.0) {
            return array_map(static fn () => 0.0, $netsByKey);
        }

        $shares = [];
        $assigned = 0.0;
        $keys = array_keys($netsByKey);
        $last = end($keys);

        foreach ($netsByKey as $key => $net) {
            if ($key === $last) {
                $shares[$key] = round($allowance - $assigned, 2);

                continue;
            }

            $share = round($allowance * $net / $total, 2);
            $shares[$key] = $share;
            $assigned += $share;
        }

        return $shares;
    }
}
