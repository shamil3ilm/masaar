<?php

declare(strict_types=1);

namespace App\Models\Inventory;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasUuid;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class StockMovement extends Model
{
    use BelongsToOrganization, HasUuid;

    public const TYPE_PURCHASE = 'purchase';
    public const TYPE_SALE = 'sale';
    public const TYPE_TRANSFER_IN = 'transfer_in';
    public const TYPE_TRANSFER_OUT = 'transfer_out';
    public const TYPE_ADJUSTMENT = 'adjustment';
    public const TYPE_RETURN_IN = 'return_in';
    public const TYPE_RETURN_OUT = 'return_out';
    public const TYPE_PRODUCTION_IN = 'production_in';
    public const TYPE_PRODUCTION_OUT = 'production_out';
    public const TYPE_OPENING = 'opening';

    public const DIRECTION_IN = 'in';
    public const DIRECTION_OUT = 'out';

    protected $fillable = [
        'organization_id',
        'product_id',
        'variant_id',
        'warehouse_id',
        'location_id',
        'movement_type',
        'direction',
        'quantity',
        'unit_cost',
        'total_cost',
        'balance_after',
        'reference_type',
        'reference_id',
        'reference_number',
        'from_warehouse_id',
        'to_warehouse_id',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'total_cost' => 'decimal:4',
            'balance_after' => 'decimal:4',
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

    public function fromWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'from_warehouse_id');
    }

    public function toWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'to_warehouse_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the reference document (invoice, bill, transfer, etc.).
     */
    public function reference(): MorphTo
    {
        return $this->morphTo('reference');
    }

    /**
     * Check if this is an incoming movement.
     */
    public function isIncoming(): bool
    {
        return $this->direction === self::DIRECTION_IN;
    }

    /**
     * Check if this is an outgoing movement.
     */
    public function isOutgoing(): bool
    {
        return $this->direction === self::DIRECTION_OUT;
    }

    /**
     * Get signed quantity (positive for in, negative for out).
     */
    public function getSignedQuantity(): float
    {
        return $this->isIncoming() ? $this->quantity : -$this->quantity;
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('movement_type', $type);
    }

    public function scopeIncoming($query)
    {
        return $query->where('direction', self::DIRECTION_IN);
    }

    public function scopeOutgoing($query)
    {
        return $query->where('direction', self::DIRECTION_OUT);
    }

    public function scopeForProduct($query, int $productId)
    {
        return $query->where('product_id', $productId);
    }

    public function scopeInWarehouse($query, int $warehouseId)
    {
        return $query->where('warehouse_id', $warehouseId);
    }

    public function scopeInDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('created_at', [$startDate, $endDate]);
    }
}
