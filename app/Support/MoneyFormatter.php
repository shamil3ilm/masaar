<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Standardized money and currency formatting utility across jurisdictions.
 *
 * Consolidates decimal formatting, thousand separators, and currency suffixes
 * to ensure uniform presentation and prevent copy-pasted number_format closures.
 */
final class MoneyFormatter
{
    /**
     * Format standard decimal amount with fixed decimal precision (e.g. "1234.50").
     */
    public static function amount(float|string|int|null $amount, int $decimals = 2): string
    {
        return number_format((float) ($amount ?? 0), $decimals, '.', '');
    }

    /**
     * Format monetary figure with currency suffix and optional thousand separators (e.g. "1,234.50 SAR").
     */
    public static function format(
        float|string|int|null $amount,
        string $currency,
        int $decimals = 2,
        bool $thousandsSeparator = true,
    ): string {
        $sep = $thousandsSeparator ? ',' : '';

        return number_format((float) ($amount ?? 0), $decimals, '.', $sep).' '.$currency;
    }

    /**
     * Format tax rate percentage (e.g. "15%").
     */
    public static function percent(float|string|int|null $percent): string
    {
        return rtrim(rtrim(number_format((float) ($percent ?? 0), 2, '.', ''), '0'), '.').'%';
    }
}

