<?php

declare(strict_types=1);

namespace App\Models\Accounting;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExchangeRate extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'from_currency',
        'to_currency',
        'rate',
        'rate_date',
    ];

    protected function casts(): array
    {
        return [
            'rate' => 'decimal:8',
            'rate_date' => 'date',
        ];
    }

    public function fromCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'from_currency', 'code');
    }

    public function toCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'to_currency', 'code');
    }

    /**
     * Get the exchange rate for a specific date.
     * Falls back to the most recent rate before that date.
     */
    public static function getRate(
        int $organizationId,
        string $fromCurrency,
        string $toCurrency,
        ?string $date = null
    ): ?float {
        if ($fromCurrency === $toCurrency) {
            return 1.0;
        }

        $date = $date ?? now()->toDateString();

        $rate = static::where('organization_id', $organizationId)
            ->where('from_currency', $fromCurrency)
            ->where('to_currency', $toCurrency)
            ->where('rate_date', '<=', $date)
            ->orderByDesc('rate_date')
            ->value('rate');

        // Try inverse rate if direct rate not found
        if ($rate === null) {
            $inverseRate = static::where('organization_id', $organizationId)
                ->where('from_currency', $toCurrency)
                ->where('to_currency', $fromCurrency)
                ->where('rate_date', '<=', $date)
                ->orderByDesc('rate_date')
                ->value('rate');

            if ($inverseRate !== null && $inverseRate > 0) {
                $rate = 1 / $inverseRate;
            }
        }

        return $rate !== null ? (float) $rate : null;
    }

    /**
     * Convert an amount from one currency to another.
     */
    public static function convert(
        float $amount,
        string $fromCurrency,
        string $toCurrency,
        int $organizationId,
        ?string $date = null
    ): ?float {
        $rate = static::getRate($organizationId, $fromCurrency, $toCurrency, $date);

        if ($rate === null) {
            return null;
        }

        return $amount * $rate;
    }
}
