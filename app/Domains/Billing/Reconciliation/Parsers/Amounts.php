<?php

declare(strict_types=1);

namespace App\Domains\Billing\Reconciliation\Parsers;

/**
 * An amount as a bank prints it - "1,150.00", "1.150,00", "-25.00",
 * "25.00-", "(25.00)" - as a positive "1150.00" and whether it was
 * negative. Null for anything that is not an amount with at most two
 * decimals.
 */
final class Amounts
{
    /**
     * @param  string  $decimal  the decimal mark: "." or ","
     * @return array{0: string, 1: bool}|null [amount, negative]
     */
    public static function parse(string $raw, string $decimal = '.'): ?array
    {
        $value = trim(str_replace(["\u{00A0}", "\u{202F}", ' ', "'"], '', $raw));
        $negative = false;

        if (preg_match('/^\((.*)\)$/', $value, $match) === 1) {
            [$value, $negative] = [$match[1], true];
        }

        if (str_ends_with($value, '-')) {
            [$value, $negative] = [substr($value, 0, -1), true];
        }

        if (str_starts_with($value, '-') || str_starts_with($value, '+')) {
            [$value, $negative] = [substr($value, 1), $negative || $value[0] === '-'];
        }

        $value = $decimal === ',' ? str_replace(['.', ','], ['', '.'], $value) : str_replace(',', '', $value);

        if (preg_match('/^\d{1,13}(\.\d{1,2})?$/', $value) !== 1) {
            return null;
        }

        [$whole, $fraction] = array_pad(explode('.', $value), 2, '');
        $amount = (ltrim($whole, '0') ?: '0').'.'.str_pad($fraction, 2, '0');

        return [$amount, $negative && $amount !== '0.00'];
    }
}
