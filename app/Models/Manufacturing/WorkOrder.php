<?php

declare(strict_types=1);

namespace App\Models\Manufacturing;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasUuid;
use App\Models\Core\Branch;
use App\Models\Core\User;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductVariant;
use App\Models\Inventory\UnitOfMeasure;
use App\Models\Inventory\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class WorkOrder extends Model
{
    use BelongsToOrganization, HasUuid, SoftDeletes;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PENDING = 'pending';
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    public const PRIORITY_LOW = 'low';
    public const PRIORITY_NORMAL = 'normal';
    public const PRIORITY_HIGH = 'high';
    public const PRIORITY_URGENT = 'urgent';

    protected $fillable = [
        'organization_id',
        'branch_id',
        'work_order_number',
        'bom_template_id',
        'sales_order_id',
        'sales_order_line_id',
        'product_id',
        'variant_id',
        'planned_quantity',
        'produced_quantity',
        'rejected_quantity',
        'unit_id',
        'planned_start_date',
        'planned_end_date',
        'actual_start_datetime',
        'actual_end_datetime',
        'source_warehouse_id',
        'target_warehouse_id',
        'estimated_material_cost',
        'estimated_labor_cost',
        'estimated_overhead_cost',
        'actual_material_cost',
        'actual_labor_cost',
        'actual_overhead_cost',
        'status',
        'priority',
        'assigned_to',
        'supervisor_id',
        'notes',
        'cancellation_reason',
        'created_by',
    ];

    protected $casts = [
        'planned_quantity' => 'decimal:4',
        'produced_quantity' => 'decimal:4',
        'rejected_quantity' => 'decimal:4',
        'planned_start_date' => 'date',
        'planned_end_date' => 'date',
        'actual_start_datetime' => 'datetime',
        'actual_end_datetime' => 'datetime',
        'estimated_material_cost' => 'decimal:4',
        'estimated_labor_cost' => 'decimal:4',
        'estimated_overhead_cost' => 'decimal:4',
        'actual_material_cost' => 'decimal:4',
        'actual_labor_cost' => 'decimal:4',
        'actual_overhead_cost' => 'decimal:4',
    ];

    // Relationships

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

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

    public function sourceWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'source_warehouse_id');
    }

    public function targetWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'target_warehouse_id');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supervisor_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function materials(): HasMany
    {
        return $this->hasMany(WorkOrderMaterial::class)->orderBy('line_order');
    }

    public function operations(): HasMany
    {
        return $this->hasMany(WorkOrderOperation::class)->orderBy('sequence');
    }

    public function productionLogs(): HasMany
    {
        return $this->hasMany(ProductionLog::class)->orderBy('logged_at', 'desc');
    }

    public function materialTransactions(): HasMany
    {
        return $this->hasMany(MaterialTransaction::class)->orderBy('transaction_datetime', 'desc');
    }

    // Scopes

    public function scopeDraft($query)
    {
        return $query->where('status', self::STATUS_DRAFT);
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeScheduled($query)
    {
        return $query->where('status', self::STATUS_SCHEDULED);
    }

    public function scopeInProgress($query)
    {
        return $query->where('status', self::STATUS_IN_PROGRESS);
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    public function scopeCancelled($query)
    {
        return $query->where('status', self::STATUS_CANCELLED);
    }

    public function scopeActive($query)
    {
        return $query->whereIn('status', [
            self::STATUS_PENDING,
            self::STATUS_SCHEDULED,
            self::STATUS_IN_PROGRESS,
        ]);
    }

    public function scopeOverdue($query)
    {
        return $query->active()
            ->where('planned_end_date', '<', now()->toDateString());
    }

    public function scopeForProduct($query, int $productId)
    {
        return $query->where('product_id', $productId);
    }

    public function scopePriority($query, string $priority)
    {
        return $query->where('priority', $priority);
    }

    public function scopeAssignedTo($query, int $userId)
    {
        return $query->where('assigned_to', $userId);
    }

    public function scopeStartingBetween($query, $startDate, $endDate)
    {
        return $query->whereBetween('planned_start_date', [$startDate, $endDate]);
    }

    // Helper Methods

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isScheduled(): bool
    {
        return $this->status === self::STATUS_SCHEDULED;
    }

    public function isInProgress(): bool
    {
        return $this->status === self::STATUS_IN_PROGRESS;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function isActive(): bool
    {
        return in_array($this->status, [
            self::STATUS_PENDING,
            self::STATUS_SCHEDULED,
            self::STATUS_IN_PROGRESS,
        ]);
    }

    public function canBeStarted(): bool
    {
        return in_array($this->status, [
            self::STATUS_PENDING,
            self::STATUS_SCHEDULED,
        ]);
    }

    public function canBeCompleted(): bool
    {
        return $this->status === self::STATUS_IN_PROGRESS;
    }

    public function canBeCancelled(): bool
    {
        return in_array($this->status, [
            self::STATUS_DRAFT,
            self::STATUS_PENDING,
            self::STATUS_SCHEDULED,
        ]);
    }

    public function canBeEdited(): bool
    {
        return in_array($this->status, [
            self::STATUS_DRAFT,
            self::STATUS_PENDING,
        ]);
    }

    public function isOverdue(): bool
    {
        return $this->isActive() && $this->planned_end_date->lt(now());
    }

    /**
     * Get remaining quantity to produce.
     */
    public function getRemainingQuantity(): float
    {
        return max(0, (float) $this->planned_quantity - (float) $this->produced_quantity);
    }

    /**
     * Get completion percentage.
     */
    public function getCompletionPercentage(): float
    {
        if ((float) $this->planned_quantity === 0.0) {
            return 0;
        }

        return round(((float) $this->produced_quantity / (float) $this->planned_quantity) * 100, 2);
    }

    /**
     * Get good quantity (produced - rejected).
     */
    public function getGoodQuantity(): float
    {
        return (float) $this->produced_quantity - (float) $this->rejected_quantity;
    }

    /**
     * Get rejection rate.
     */
    public function getRejectionRate(): float
    {
        if ((float) $this->produced_quantity === 0.0) {
            return 0;
        }

        return round(((float) $this->rejected_quantity / (float) $this->produced_quantity) * 100, 2);
    }

    /**
     * Get total estimated cost.
     */
    public function getTotalEstimatedCost(): float
    {
        return (float) bcadd(
            bcadd((string) $this->estimated_material_cost, (string) $this->estimated_labor_cost, 4),
            (string) $this->estimated_overhead_cost,
            4
        );
    }

    /**
     * Get total actual cost.
     */
    public function getTotalActualCost(): float
    {
        return (float) bcadd(
            bcadd((string) $this->actual_material_cost, (string) $this->actual_labor_cost, 4),
            (string) $this->actual_overhead_cost,
            4
        );
    }

    /**
     * Get cost variance.
     */
    public function getCostVariance(): float
    {
        return (float) bcsub(
            (string) $this->getTotalActualCost(),
            (string) $this->getTotalEstimatedCost(),
            4
        );
    }

    /**
     * Get unit cost.
     */
    public function getUnitCost(): float
    {
        $goodQuantity = $this->getGoodQuantity();

        if ($goodQuantity === 0.0) {
            return 0;
        }

        return (float) bcdiv((string) $this->getTotalActualCost(), (string) $goodQuantity, 4);
    }

    /**
     * Get operations progress.
     */
    public function getOperationsProgress(): array
    {
        $total = $this->operations()->count();
        $completed = $this->operations()->where('status', WorkOrderOperation::STATUS_COMPLETED)->count();

        return [
            'total' => $total,
            'completed' => $completed,
            'pending' => $total - $completed,
            'percentage' => $total > 0 ? round(($completed / $total) * 100, 2) : 0,
        ];
    }

    /**
     * Get materials consumption summary.
     */
    public function getMaterialsConsumptionSummary(): array
    {
        $materials = $this->materials()->get();

        return [
            'total_items' => $materials->count(),
            'total_required' => $materials->sum('required_quantity'),
            'total_issued' => $materials->sum('issued_quantity'),
            'total_consumed' => $materials->sum('consumed_quantity'),
            'total_returned' => $materials->sum('returned_quantity'),
            'total_wastage' => $materials->sum('wastage_quantity'),
        ];
    }

    /**
     * Start the work order.
     */
    public function start(): void
    {
        $this->update([
            'status' => self::STATUS_IN_PROGRESS,
            'actual_start_datetime' => now(),
        ]);
    }

    /**
     * Complete the work order.
     */
    public function complete(): void
    {
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'actual_end_datetime' => now(),
        ]);
    }

    /**
     * Cancel the work order.
     */
    public function cancel(string $reason): void
    {
        $this->update([
            'status' => self::STATUS_CANCELLED,
            'cancellation_reason' => $reason,
        ]);
    }

    /**
     * Get display name.
     */
    public function getDisplayName(): string
    {
        return $this->work_order_number;
    }
}
