<?php

declare(strict_types=1);

namespace App\Models\Manufacturing;

use App\Models\Inventory\Product;
use App\Models\Inventory\ProductVariant;
use App\Models\Inventory\UnitOfMeasure;
use App\Models\Inventory\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BomLine extends Model
{
    protected $fillable = [
        'bom_template_id',
        'product_id',
        'variant_id',
        'description',
        'quantity',
        'unit_id',
        'unit_cost',
        'wastage_percentage',
        'is_critical',
        'warehouse_id',
        'line_order',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'unit_cost' => 'decimal:4',
        'wastage_percentage' => 'decimal:2',
        'is_critical' => 'boolean',
        'line_order' => 'integer',
    ];

    // Relationships

    public function bomTemplate(): BelongsTo
    {
        return $this->belongsTo(BomTemplate::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'unit_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    // Scopes

    public function scopeCritical($query)
    {
        return $query->where('is_critical', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('line_order');
    }

    // Helper Methods

    /**
     * Calculate quantity with wastage.
     */
    public function getAdjustedQuantity(float $multiplier = 1): float
    {
        $baseQuantity = (float) $this->quantity * $multiplier;
        $wastageMultiplier = 1 + ((float) $this->wastage_percentage / 100);

        return round($baseQuantity * $wastageMultiplier, 4);
    }

    /**
     * Calculate line cost.
     */
    public function getLineCost(float $multiplier = 1): float
    {
        $quantity = $this->getAdjustedQuantity($multiplier);

        return (float) bcmul((string) $quantity, (string) ($this->unit_cost ?? 0), 4);
    }

    /**
     * Get display description.
     */
    public function getDisplayDescription(): string
    {
        if ($this->description) {
            return $this->description;
        }

        $description = $this->product->name;

        if ($this->variant) {
            $description .= " ({$this->variant->name})";
        }

        return $description;
    }
}
