<?php

declare(strict_types=1);

namespace App\Models\Inventory;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockLevel extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'product_id',
        'variant_id',
        'warehouse_id',
        'location_id',
        'quantity',
        'reserved_quantity',
        'average_cost',
        'last_purchase_price',
        'total_value',
        'reorder_level',
        'reorder_quantity',
        'maximum_stock',
        'last_count_date',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'reserved_quantity' => 'decimal:4',
            'average_cost' => 'decimal:4',
            'last_purchase_price' => 'decimal:4',
            'total_value' => 'decimal:4',
            'reorder_level' => 'decimal:4',
            'reorder_quantity' => 'decimal:4',
            'maximum_stock' => 'decimal:4',
            'last_count_date' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(WarehouseLocation::class, 'location_id');
    }

    /**
     * Get available quantity (quantity - reserved).
     */
    public function getAvailableQuantity(): float
    {
        return max(0, $this->quantity - $this->reserved_quantity);
    }

    /**
     * Check if quantity is available.
     */
    public function hasAvailable(float $quantity): bool
    {
        return $this->getAvailableQuantity() >= $quantity;
    }

    /**
     * Check if stock is below reorder level.
     */
    public function needsReorder(): bool
    {
        $reorderLevel = $this->reorder_level ?? $this->product->reorder_level;

        if (!$reorderLevel) {
            return false;
        }

        return $this->quantity <= $reorderLevel;
    }

    /**
     * Check if stock exceeds maximum.
     */
    public function isOverstocked(): bool
    {
        if (!$this->maximum_stock) {
            return false;
        }

        return $this->quantity > $this->maximum_stock;
    }

    /**
     * Reserve stock for an order.
     */
    public function reserve(float $quantity): bool
    {
        if (!$this->hasAvailable($quantity)) {
            return false;
        }

        $this->increment('reserved_quantity', $quantity);
        return true;
    }

    /**
     * Release reserved stock.
     */
    public function release(float $quantity): void
    {
        $this->decrement('reserved_quantity', min($quantity, $this->reserved_quantity));
    }

    /**
     * Recalculate total value based on quantity and average cost.
     */
    public function recalculateTotalValue(): void
    {
        $this->total_value = $this->quantity * $this->average_cost;
        $this->saveQuietly();
    }

    public function scopeLowStock($query)
    {
        return $query->whereRaw('quantity <= COALESCE(reorder_level, 0)');
    }

    public function scopeInWarehouse($query, int $warehouseId)
    {
        return $query->where('warehouse_id', $warehouseId);
    }

    public function scopeHasStock($query)
    {
        return $query->where('quantity', '>', 0);
    }
}
