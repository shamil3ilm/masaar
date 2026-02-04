<?php

declare(strict_types=1);

namespace App\Models\Manufacturing;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Core\User;
use App\Models\Inventory\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaterialTransaction extends Model
{
    use BelongsToOrganization;

    public const TYPE_ISSUE = 'issue';
    public const TYPE_RETURN = 'return';
    public const TYPE_WASTAGE = 'wastage';

    protected $fillable = [
        'organization_id',
        'work_order_id',
        'work_order_material_id',
        'transaction_type',
        'transaction_datetime',
        'quantity',
        'unit_cost',
        'warehouse_id',
        'stock_movement_id',
        'reference',
        'notes',
        'processed_by',
    ];

    protected $casts = [
        'transaction_datetime' => 'datetime',
        'quantity' => 'decimal:4',
        'unit_cost' => 'decimal:4',
    ];

    // Relationships

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function workOrderMaterial(): BelongsTo
    {
        return $this->belongsTo(WorkOrderMaterial::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function processedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    // Scopes

    public function scopeIssues($query)
    {
        return $query->where('transaction_type', self::TYPE_ISSUE);
    }

    public function scopeReturns($query)
    {
        return $query->where('transaction_type', self::TYPE_RETURN);
    }

    public function scopeWastages($query)
    {
        return $query->where('transaction_type', self::TYPE_WASTAGE);
    }

    public function scopeForWorkOrder($query, int $workOrderId)
    {
        return $query->where('work_order_id', $workOrderId);
    }

    public function scopeForMaterial($query, int $workOrderMaterialId)
    {
        return $query->where('work_order_material_id', $workOrderMaterialId);
    }

    public function scopeTransactedBetween($query, $startDate, $endDate)
    {
        return $query->whereBetween('transaction_datetime', [$startDate, $endDate]);
    }

    // Helper Methods

    public function isIssue(): bool
    {
        return $this->transaction_type === self::TYPE_ISSUE;
    }

    public function isReturn(): bool
    {
        return $this->transaction_type === self::TYPE_RETURN;
    }

    public function isWastage(): bool
    {
        return $this->transaction_type === self::TYPE_WASTAGE;
    }

    /**
     * Get total value.
     */
    public function getTotalValue(): float
    {
        return (float) bcmul((string) $this->quantity, (string) $this->unit_cost, 4);
    }

    /**
     * Get signed quantity (negative for returns).
     */
    public function getSignedQuantity(): float
    {
        if ($this->isReturn()) {
            return (float) bcmul('-1', (string) $this->quantity, 4);
        }

        return (float) $this->quantity;
    }

    /**
     * Get transaction type label.
     */
    public function getTypeLabel(): string
    {
        return match ($this->transaction_type) {
            self::TYPE_ISSUE => 'Material Issue',
            self::TYPE_RETURN => 'Material Return',
            self::TYPE_WASTAGE => 'Material Wastage',
            default => $this->transaction_type,
        };
    }
}
