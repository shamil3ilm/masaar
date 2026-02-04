<?php

declare(strict_types=1);

namespace App\Services\Core;

use App\Models\Accounting\Currency;
use InvalidArgumentException;

/**
 * Service for handling financial calculations with proper precision.
 * Uses BCMath for precise decimal arithmetic.
 */
class MoneyService
{
    private int $defaultScale = 4; // Internal calculation precision
    private int $displayScale = 2; // Display precision

    /**
     * Add two amounts with precision.
     */
    public function add(string|float|int $a, string|float|int $b, ?int $scale = null): string
    {
        return bcadd($this->normalize($a), $this->normalize($b), $scale ?? $this->defaultScale);
    }

    /**
     * Subtract two amounts with precision.
     */
    public function subtract(string|float|int $a, string|float|int $b, ?int $scale = null): string
    {
        return bcsub($this->normalize($a), $this->normalize($b), $scale ?? $this->defaultScale);
    }

    /**
     * Multiply two amounts with precision.
     */
    public function multiply(string|float|int $a, string|float|int $b, ?int $scale = null): string
    {
        return bcmul($this->normalize($a), $this->normalize($b), $scale ?? $this->defaultScale);
    }

    /**
     * Divide two amounts with precision.
     */
    public function divide(string|float|int $a, string|float|int $b, ?int $scale = null): string
    {
        $divisor = $this->normalize($b);

        if (bccomp($divisor, '0', $this->defaultScale) === 0) {
            throw new InvalidArgumentException('Division by zero');
        }

        return bcdiv($this->normalize($a), $divisor, $scale ?? $this->defaultScale);
    }

    /**
     * Calculate percentage of an amount.
     */
    public function percentage(string|float|int $amount, string|float|int $percent, ?int $scale = null): string
    {
        return $this->divide(
            $this->multiply($amount, $percent, $scale),
            '100',
            $scale
        );
    }

    /**
     * Round to specified decimal places using banker's rounding.
     */
    public function round(string|float|int $amount, int $decimals = 2): string
    {
        $amount = $this->normalize($amount);

        // Add a tiny amount for proper rounding
        $pow = bcpow('10', (string) $decimals, 0);
        $scaled = bcmul($amount, $pow, $decimals + 1);

        // Banker's rounding (round half to even)
        $integer = bcadd($scaled, '0', 0);
        $decimal = bcsub($scaled, $integer, $decimals + 1);

        if (bccomp($decimal, '0.5', $decimals + 1) === 0) {
            // Exactly 0.5 - round to even
            if (((int) $integer) % 2 !== 0) {
                $integer = bcadd($integer, '1', 0);
            }
        } elseif (bccomp($decimal, '0.5', $decimals + 1) > 0) {
            $integer = bcadd($integer, '1', 0);
        }

        return bcdiv($integer, $pow, $decimals);
    }

    /**
     * Round for display (standard rounding).
     */
    public function roundForDisplay(string|float|int $amount, ?int $decimals = null): string
    {
        $decimals = $decimals ?? $this->displayScale;
        return number_format((float) $this->normalize($amount), $decimals, '.', '');
    }

    /**
     * Compare two amounts.
     * Returns: -1 if a < b, 0 if equal, 1 if a > b
     */
    public function compare(string|float|int $a, string|float|int $b, ?int $scale = null): int
    {
        return bccomp(
            $this->normalize($a),
            $this->normalize($b),
            $scale ?? $this->defaultScale
        );
    }

    /**
     * Check if amount is zero.
     */
    public function isZero(string|float|int $amount, ?int $scale = null): bool
    {
        return $this->compare($amount, '0', $scale) === 0;
    }

    /**
     * Check if amount is positive (greater than zero).
     */
    public function isPositive(string|float|int $amount): bool
    {
        return $this->compare($amount, '0') > 0;
    }

    /**
     * Check if amount is negative (less than zero).
     */
    public function isNegative(string|float|int $amount): bool
    {
        return $this->compare($amount, '0') < 0;
    }

    /**
     * Get absolute value.
     */
    public function abs(string|float|int $amount): string
    {
        $normalized = $this->normalize($amount);

        if ($this->isNegative($normalized)) {
            return bcmul($normalized, '-1', $this->defaultScale);
        }

        return $normalized;
    }

    /**
     * Sum an array of amounts.
     */
    public function sum(array $amounts, ?int $scale = null): string
    {
        $total = '0';

        foreach ($amounts as $amount) {
            $total = $this->add($total, $amount, $scale);
        }

        return $total;
    }

    /**
     * Calculate line total: quantity × unit_price - discount + tax.
     */
    public function calculateLineTotal(
        string|float|int $quantity,
        string|float|int $unitPrice,
        string|float|int $discountAmount = '0',
        string|float|int $taxAmount = '0'
    ): array {
        $subtotal = $this->multiply($quantity, $unitPrice);
        $afterDiscount = $this->subtract($subtotal, $discountAmount);
        $total = $this->add($afterDiscount, $taxAmount);

        return [
            'subtotal' => $this->round($subtotal),
            'discount' => $this->round($discountAmount),
            'tax' => $this->round($taxAmount),
            'total' => $this->round($total),
        ];
    }

    /**
     * Allocate an amount across multiple items proportionally.
     * Handles rounding differences.
     */
    public function allocate(string|float|int $total, array $proportions): array
    {
        $total = $this->normalize($total);
        $proportionSum = $this->sum($proportions);

        if ($this->isZero($proportionSum)) {
            throw new InvalidArgumentException('Proportions sum to zero');
        }

        $allocations = [];
        $allocated = '0';

        foreach ($proportions as $key => $proportion) {
            $share = $this->divide(
                $this->multiply($total, $proportion),
                $proportionSum
            );

            $allocations[$key] = $this->round($share);
            $allocated = $this->add($allocated, $allocations[$key]);
        }

        // Handle rounding difference - add to largest allocation
        $diff = $this->subtract($total, $allocated);
        if (!$this->isZero($diff)) {
            // Find the largest allocation
            $maxKey = array_keys($allocations, max($allocations))[0];
            $allocations[$maxKey] = $this->add($allocations[$maxKey], $diff);
        }

        return $allocations;
    }

    /**
     * Validate that line items sum to total (within tolerance).
     */
    public function validateTotal(array $lineAmounts, string|float|int $expectedTotal, ?string $tolerance = null): bool
    {
        $tolerance = $tolerance ?? '0.01';
        $actual = $this->sum($lineAmounts);
        $diff = $this->abs($this->subtract($actual, $expectedTotal));

        return $this->compare($diff, $tolerance) <= 0;
    }

    /**
     * Format amount for display.
     */
    public function format(
        string|float|int $amount,
        ?string $currencyCode = null,
        bool $showSymbol = true
    ): string {
        $amount = $this->normalize($amount);
        $decimals = $this->displayScale;
        $symbol = '';

        if ($currencyCode) {
            $currency = Currency::where('code', $currencyCode)->first();
            if ($currency) {
                $decimals = $currency->decimal_places;
                if ($showSymbol) {
                    $symbol = $currency->symbol . ' ';
                }
            }
        }

        $formatted = number_format((float) $amount, $decimals);

        return $symbol . $formatted;
    }

    /**
     * Normalize input to string for BCMath operations.
     */
    protected function normalize(string|float|int $value): string
    {
        if (is_float($value)) {
            // Avoid floating point precision issues
            return number_format($value, $this->defaultScale, '.', '');
        }

        return (string) $value;
    }

    /**
     * Convert between currencies.
     */
    public function convertCurrency(
        string|float|int $amount,
        string|float|int $exchangeRate,
        ?int $targetDecimals = null
    ): string {
        $converted = $this->multiply($amount, $exchangeRate);

        return $this->round($converted, $targetDecimals ?? $this->displayScale);
    }
}
