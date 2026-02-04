<?php

declare(strict_types=1);

namespace App\Models\Tax;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TaxCategory extends Model
{
    use BelongsToOrganization;

    // Standard tax category codes (ZATCA/GCC compliant)
    public const CODE_STANDARD = 'S';      // Standard rate
    public const CODE_ZERO = 'Z';          // Zero rated
    public const CODE_EXEMPT = 'E';        // Exempt
    public const CODE_OUT_OF_SCOPE = 'O';  // Out of scope

    protected $fillable = [
        'organization_id',
        'name',
        'code',
        'description',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function taxRates(): HasMany
    {
        return $this->hasMany(TaxRate::class);
    }

    /**
     * Get the current applicable tax rate for a country.
     */
    public function getCurrentRate(string $countryCode): ?TaxRate
    {
        return $this->taxRates()
            ->where('country_code', $countryCode)
            ->where('is_active', true)
            ->where('effective_from', '<=', now())
            ->where(function ($query) {
                $query->whereNull('effective_to')
                    ->orWhere('effective_to', '>=', now());
            })
            ->orderByDesc('effective_from')
            ->first();
    }

    /**
     * Check if this category is taxable.
     */
    public function isTaxable(): bool
    {
        return $this->code === self::CODE_STANDARD;
    }

    /**
     * Check if this is zero-rated.
     */
    public function isZeroRated(): bool
    {
        return $this->code === self::CODE_ZERO;
    }

    /**
     * Check if this is exempt.
     */
    public function isExempt(): bool
    {
        return $this->code === self::CODE_EXEMPT;
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByCode($query, string $code)
    {
        return $query->where('code', $code);
    }
}
