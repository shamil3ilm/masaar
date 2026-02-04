<?php

declare(strict_types=1);

namespace App\Models\Inventory;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UnitOfMeasure extends Model
{
    use BelongsToOrganization;

    protected $table = 'units_of_measure';

    protected $fillable = [
        'organization_id',
        'name',
        'symbol',
        'base_unit_id',
        'conversion_factor',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'conversion_factor' => 'decimal:8',
            'is_active' => 'boolean',
        ];
    }

    public function baseUnit(): BelongsTo
    {
        return $this->belongsTo(self::class, 'base_unit_id');
    }

    public function derivedUnits(): HasMany
    {
        return $this->hasMany(self::class, 'base_unit_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'unit_id');
    }

    /**
     * Convert a quantity from this unit to the base unit.
     */
    public function toBase(float $quantity): float
    {
        return $quantity * (float) $this->conversion_factor;
    }

    /**
     * Convert a quantity from the base unit to this unit.
     */
    public function fromBase(float $quantity): float
    {
        if ($this->conversion_factor == 0) {
            return 0;
        }

        return $quantity / (float) $this->conversion_factor;
    }

    /**
     * Convert quantity to another unit.
     */
    public function convertTo(float $quantity, self $targetUnit): float
    {
        // First convert to base, then to target
        $baseQuantity = $this->toBase($quantity);
        return $targetUnit->fromBase($baseQuantity);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeBaseUnits($query)
    {
        return $query->whereNull('base_unit_id');
    }
}
