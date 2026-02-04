<?php

declare(strict_types=1);

namespace App\Models\Inventory;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockAdjustmentLine extends Model
{
    protected $fillable = [
        'stock_adjustment_id',
        'product_id',
        'variant_id',
        'location_id',
        'system_quantity',
        'actual_quantity',
        'difference',
        'unit_cost',
        'total_cost',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'system_quantity' => 'decimal:4',
            'actual_quantity' => 'decimal:4',
            'difference' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'total_cost' => 'decimal:4',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (StockAdjustmentLine $line) {
            // Auto-calculate difference and total cost
            $line->difference = bcsub((string) $line->actual_quantity, (string) $line->system_quantity, 4);
            $line->total_cost = bcmul((string) abs((float) $line->difference), (string) $line->unit_cost, 4);
        });
    }

    public function stockAdjustment(): BelongsTo
    {
        return $this->belongsTo(StockAdjustment::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(WarehouseLocation::class, 'location_id');
    }

    /**
     * Check if this is an increase adjustment.
     */
    public function isIncrease(): bool
    {
        return $this->difference > 0;
    }

    /**
     * Check if this is a decrease adjustment.
     */
    public function isDecrease(): bool
    {
        return $this->difference < 0;
    }

    /**
     * Check if there's no change.
     */
    public function hasNoChange(): bool
    {
        return bccomp((string) $this->difference, '0', 4) === 0;
    }

    /**
     * Get the absolute difference value.
     */
    public function getAbsoluteDifference(): float
    {
        return abs((float) $this->difference);
    }
}
