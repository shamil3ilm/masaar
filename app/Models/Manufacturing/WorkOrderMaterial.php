<?php

declare(strict_types=1);

namespace App\Models\Manufacturing;

use App\Models\Inventory\Product;
use App\Models\Inventory\ProductVariant;
use App\Models\Inventory\UnitOfMeasure;
use App\Models\Inventory\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkOrderMaterial extends Model
{
    protected $fillable = [
        'work_order_id',
        'bom_line_id',
        'product_id',
        'variant_id',
        'description',
        'required_quantity',
        'issued_quantity',
        'consumed_quantity',
        'returned_quantity',
        'wastage_quantity',
        'unit_id',
        'unit_cost',
        'total_cost',
        'warehouse_id',
        'line_order',
    ];

    protected $casts = [
        'required_quantity' => 'decimal:4',
        'issued_quantity' => 'decimal:4',
        'consumed_quantity' => 'decimal:4',
        'returned_quantity' => 'decimal:4',
        'wastage_quantity' => 'decimal:4',
        'unit_cost' => 'decimal:4',
        'total_cost' => 'decimal:4',
        'line_order' => 'integer',
    ];

    // Relationships

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function bomLine(): BelongsTo
    {
        return $this->belongsTo(BomLine::class);
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

    public function transactions(): HasMany
    {
        return $this->hasMany(MaterialTransaction::class)->orderBy('transaction_datetime', 'desc');
    }

    // Scopes

    public function scopeOrdered($query)
    {
        return $query->orderBy('line_order');
    }

    public function scopeFullyIssued($query)
    {
        return $query->whereColumn('issued_quantity', '>=', 'required_quantity');
    }

    public function scopePendingIssue($query)
    {
        return $query->whereColumn('issued_quantity', '<', 'required_quantity');
    }

    // Helper Methods

    /**
     * Get pending issue quantity.
     */
    public function getPendingIssueQuantity(): float
    {
        return max(0, (float) $this->required_quantity - (float) $this->issued_quantity);
    }

    /**
     * Get available quantity (issued - consumed - wastage).
     */
    public function getAvailableQuantity(): float
    {
        return (float) bcsub(
            bcsub((string) $this->issued_quantity, (string) $this->consumed_quantity, 4),
            (string) $this->wastage_quantity,
            4
        );
    }

    /**
     * Check if material is fully issued.
     */
    public function isFullyIssued(): bool
    {
        return (float) $this->issued_quantity >= (float) $this->required_quantity;
    }

    /**
     * Check if material is fully consumed.
     */
    public function isFullyConsumed(): bool
    {
        return $this->getAvailableQuantity() <= 0;
    }

    /**
     * Get consumption percentage.
     */
    public function getConsumptionPercentage(): float
    {
        if ((float) $this->required_quantity === 0.0) {
            return 0;
        }

        return round(((float) $this->consumed_quantity / (float) $this->required_quantity) * 100, 2);
    }

    /**
     * Get wastage percentage.
     */
    public function getWastagePercentage(): float
    {
        $totalUsed = (float) bcadd((string) $this->consumed_quantity, (string) $this->wastage_quantity, 4);

        if ($totalUsed === 0.0) {
            return 0;
        }

        return round(((float) $this->wastage_quantity / $totalUsed) * 100, 2);
    }

    /**
     * Record material issue.
     */
    public function recordIssue(float $quantity): void
    {
        $newIssued = bcadd((string) $this->issued_quantity, (string) $quantity, 4);

        $this->update([
            'issued_quantity' => $newIssued,
        ]);
    }

    /**
     * Record material consumption.
     */
    public function recordConsumption(float $quantity): void
    {
        $newConsumed = bcadd((string) $this->consumed_quantity, (string) $quantity, 4);

        $this->update([
            'consumed_quantity' => $newConsumed,
        ]);

        $this->recalculateTotalCost();
    }

    /**
     * Record material return.
     */
    public function recordReturn(float $quantity): void
    {
        $newReturned = bcadd((string) $this->returned_quantity, (string) $quantity, 4);
        $newIssued = bcsub((string) $this->issued_quantity, (string) $quantity, 4);

        $this->update([
            'returned_quantity' => $newReturned,
            'issued_quantity' => max(0, $newIssued),
        ]);
    }

    /**
     * Record material wastage.
     */
    public function recordWastage(float $quantity): void
    {
        $newWastage = bcadd((string) $this->wastage_quantity, (string) $quantity, 4);

        $this->update([
            'wastage_quantity' => $newWastage,
        ]);

        $this->recalculateTotalCost();
    }

    /**
     * Recalculate total cost.
     */
    public function recalculateTotalCost(): void
    {
        $usedQuantity = bcadd((string) $this->consumed_quantity, (string) $this->wastage_quantity, 4);
        $totalCost = bcmul($usedQuantity, (string) $this->unit_cost, 4);

        $this->update(['total_cost' => $totalCost]);
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
