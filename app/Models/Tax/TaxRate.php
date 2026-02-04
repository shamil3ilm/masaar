<?php

declare(strict_types=1);

namespace App\Models\Tax;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaxRate extends Model
{
    protected $fillable = [
        'tax_category_id',
        'name',
        'rate',
        'country_code',
        'effective_from',
        'effective_to',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'rate' => 'decimal:4',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function taxCategory(): BelongsTo
    {
        return $this->belongsTo(TaxCategory::class);
    }

    /**
     * Calculate tax amount for a given base amount.
     */
    public function calculateTax(float $amount): float
    {
        return bcmul((string) $amount, bcdiv((string) $this->rate, '100', 6), 4);
    }

    /**
     * Calculate base amount from tax-inclusive amount.
     */
    public function extractBaseFromInclusive(float $inclusiveAmount): float
    {
        $divisor = bcadd('1', bcdiv((string) $this->rate, '100', 6), 6);
        return bcdiv((string) $inclusiveAmount, $divisor, 4);
    }

    /**
     * Check if rate is currently effective.
     */
    public function isCurrentlyEffective(): bool
    {
        $now = now()->toDateString();

        if ($this->effective_from > $now) {
            return false;
        }

        if ($this->effective_to && $this->effective_to < $now) {
            return false;
        }

        return true;
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForCountry($query, string $countryCode)
    {
        return $query->where('country_code', $countryCode);
    }

    public function scopeEffectiveOn($query, $date = null)
    {
        $date = $date ?? now()->toDateString();

        return $query
            ->where('effective_from', '<=', $date)
            ->where(function ($q) use ($date) {
                $q->whereNull('effective_to')
                    ->orWhere('effective_to', '>=', $date);
            });
    }
}
